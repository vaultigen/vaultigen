<?php

use App\Models\BackupRun;
use App\Models\Entry;
use App\Models\FileAttachment;
use App\Models\KeyRotationRun;
use App\Models\User;
use App\Services\EntryVault;
use App\Services\Operations\KeyRotationService;
use App\Services\Operations\MaintenanceWindow;
use App\Services\Operations\OperationFailedException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake('local');
    config(['vaultigen.backup_root' => Storage::disk('local')->path('backups')]);
    Storage::fake('attachments');
    config([
        'vaultigen.backup_keyring' => json_encode([
            'active_key_id' => 'backup-test-v1',
            'keys' => ['backup-test-v1' => base64_encode(str_repeat('b', 32))],
        ], JSON_THROW_ON_ERROR),
    ]);
});

function vaultigen_second_kek(): void
{
    config(['vaultigen.kek_keyring' => json_encode([
        'active_key_id' => 'kek-test-v2',
        'keys' => [
            'kek-test-v1' => base64_encode(str_repeat('k', 32)),
            'kek-test-v2' => base64_encode(str_repeat('j', 32)),
        ],
    ], JSON_THROW_ON_ERROR)]);
}

it('rewraps every vault key under the active installation KEK', function (): void {
    vaultigen_entry_fixture();
    vaultigen_second_kek();

    $service = app(KeyRotationService::class);
    $run = $service->startInstallationRewrap(null);

    expect($run->source_key_id)->toBe('kek-test-v1')
        ->and($run->target_key_id)->toBe('kek-test-v2');

    $service->run($run);
    $run->refresh();

    expect($run->status)->toBe(KeyRotationRun::STATUS_COMPLETED)
        ->and(DB::table('vault_key_wrappings')->where('recipient_key_id', 'kek-test-v1')->count())->toBe(0)
        ->and(DB::table('vault_key_wrappings')->where('recipient_key_id', 'kek-test-v2')->count())->toBeGreaterThan(0);
});

it('does nothing to rotate when only the active KEK is referenced', function (): void {
    vaultigen_entry_fixture();

    expect(fn () => app(KeyRotationService::class)->startInstallationRewrap(null))
        ->toThrow(OperationFailedException::class);
});

it('reports leftover references when a retired wrapping still uses the old KEK', function (): void {
    vaultigen_entry_fixture();
    $second = vaultigen_entry_fixture();
    DB::table('vault_key_wrappings')
        ->whereIn('vault_key_version_id', function ($query) use ($second): void {
            $query->select('id')->from('vault_key_versions')->where('vault_id', $second['vault']->id);
        })
        ->update(['retired_at' => now()]);
    vaultigen_second_kek();

    $service = app(KeyRotationService::class);
    $run = $service->startInstallationRewrap(null);
    $service->run($run);

    expect($run->refresh()->error_code)->toBe('references_remain');
});

it('requires fresh strong reauthentication and administrator rights to start', function (): void {
    vaultigen_entry_fixture();
    vaultigen_second_kek();
    $plain = User::factory()->withTwoFactor()->create();

    expect(fn () => app(KeyRotationService::class)->startInstallationRewrap($plain))
        ->toThrow(AuthorizationException::class);

    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    expect(fn () => app(KeyRotationService::class)->startInstallationRewrap($admin))
        ->toThrow(RuntimeException::class);
});

it('rotates a vault key and re-encrypts entries, revisions, and attachments', function (): void {
    ['alice' => $alice, 'vault' => $vault, 'admin' => $admin] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachment = vaultigen_upload_attachment($alice, $entry, 'file-bytes');
    app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice, ['title' => 'Updated', 'password' => 'rotated-secret']), 1);
    $revisionId = (string) DB::table('entry_revisions')->where('entry_id', $entry->id)->value('id');
    BackupRun::factory()->completed()->create(['requested_by_user_id' => $admin->id]);

    $service = app(KeyRotationService::class);
    $run = $service->startVaultRotation(null, $vault->id);
    $source = (string) $run->source_key_id;
    $service->run($run);
    $run->refresh();

    expect($run->status)->toBe(KeyRotationRun::STATUS_COMPLETED)
        ->and(DB::table('vault_key_versions')->where('id', $source)->value('retired_at'))->not->toBeNull()
        ->and(DB::table('entries')->where('vault_key_version_id', $source)->count())->toBe(0)
        ->and(DB::table('entry_revisions')->where('vault_key_version_id', $source)->count())->toBe(0)
        ->and(DB::table('file_attachments')->where('vault_key_version_id', $source)->count())->toBe(0);

    expect(vaultigen_decrypt_attachment(FileAttachment::query()->findOrFail($attachment->id)))->toBe('file-bytes');

    app(EntryVault::class)->restore($alice, $entry->id, $revisionId);
    expect(Entry::query()->findOrFail($entry->id)->title)->toBe('Example login');
});

it('refuses to retire a key until a verified backup exists, then resumes', function (): void {
    ['alice' => $alice, 'vault' => $vault, 'admin' => $admin] = vaultigen_entry_fixture();
    vaultigen_make_entry($alice, $vault);

    $service = app(KeyRotationService::class);
    $run = $service->startVaultRotation(null, $vault->id);
    $service->run($run);

    expect($run->refresh()->status)->toBe(KeyRotationRun::STATUS_FAILED)
        ->and($run->error_code)->toBe('backup_required');

    BackupRun::factory()->completed()->create(['requested_by_user_id' => $admin->id]);
    $service->run($run->refresh());

    expect($run->refresh()->status)->toBe(KeyRotationRun::STATUS_COMPLETED);
});

it('reports remaining references when an entry cannot be re-encrypted', function (): void {
    ['alice' => $alice, 'vault' => $vault, 'admin' => $admin] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    BackupRun::factory()->completed()->create(['requested_by_user_id' => $admin->id]);
    DB::table('entries')->where('id', $entry->id)->update(['encrypted_payload' => 'corrupt']);

    $service = app(KeyRotationService::class);
    $run = $service->startVaultRotation(null, $vault->id);
    $service->run($run);

    expect($run->refresh()->status)->toBe(KeyRotationRun::STATUS_FAILED)
        ->and($run->error_code)->toBe('references_remain')
        ->and($run->failed_count)->toBeGreaterThan(0);
});

it('marks an unknown rotation operation failed', function (): void {
    ['vault' => $vault, 'admin' => $admin] = vaultigen_entry_fixture();
    $run = KeyRotationRun::factory()->create([
        'operation' => 'bogus',
        'vault_id' => $vault->id,
        'source_key_id' => 'a',
        'target_key_id' => 'b',
        'started_by_user_id' => $admin->id,
    ]);

    app(KeyRotationService::class)->run($run);

    expect($run->refresh()->error_code)->toBe('unknown_operation');
});

it('refuses to start while a maintenance window, rotation, or backup is active', function (): void {
    ['vault' => $vault, 'admin' => $admin] = vaultigen_entry_fixture();
    $service = app(KeyRotationService::class);

    app(MaintenanceWindow::class)->begin('backup', null, 'test');
    expect(fn () => $service->startVaultRotation(null, $vault->id))->toThrow(OperationFailedException::class);
    app(MaintenanceWindow::class)->end();

    KeyRotationRun::factory()->create(['status' => KeyRotationRun::STATUS_PENDING, 'started_by_user_id' => $admin->id]);
    expect(fn () => $service->startVaultRotation(null, $vault->id))->toThrow(OperationFailedException::class);
    DB::table('key_rotation_runs')->delete();

    BackupRun::factory()->create(['state' => BackupRun::STATE_RUNNING, 'requested_by_user_id' => $admin->id]);
    expect(fn () => $service->startVaultRotation(null, $vault->id))->toThrow(OperationFailedException::class);
});

it('reports a missing vault when starting a vault rotation', function (): void {
    expect(fn () => app(KeyRotationService::class)->startVaultRotation(null, (string) Str::uuid()))
        ->toThrow(OperationFailedException::class);
});

it('does not run a rotation that already completed', function (): void {
    ['vault' => $vault, 'admin' => $admin] = vaultigen_entry_fixture();
    $run = KeyRotationRun::factory()->create([
        'operation' => KeyRotationRun::OPERATION_VAULT_ROTATE,
        'vault_id' => $vault->id,
        'status' => KeyRotationRun::STATUS_COMPLETED,
        'started_by_user_id' => $admin->id,
    ]);

    app(KeyRotationService::class)->run($run);

    expect($run->refresh()->status)->toBe(KeyRotationRun::STATUS_COMPLETED);
});
