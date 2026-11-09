<?php

use App\Models\BackupRun;
use App\Models\ImportRun;
use App\Models\KeyRotationRun;
use App\Models\User;
use App\Services\Crypto\FileStreamCipher;
use App\Services\Operations\BackupCipher;
use App\Services\Operations\BackupService;
use App\Services\Operations\DatabaseSnapshot;
use App\Services\Operations\MaintenanceWindow;
use App\Services\Operations\OperationFailedException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\FakeBackupRuntime;

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

it('refuses to start for a non-administrator', function (): void {
    $user = User::factory()->withTwoFactor()->create();

    expect(fn () => app(BackupService::class)->start($user, BackupRun::TRIGGER_MANUAL))
        ->toThrow(AuthorizationException::class);
});

it('refuses to start while a maintenance window is open', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    app(MaintenanceWindow::class)->begin('backup', null, 'test');

    expect(fn () => app(BackupService::class)->start($admin, BackupRun::TRIGGER_MANUAL))
        ->toThrow(OperationFailedException::class);
});

it('refuses to start while a backup is pending', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    BackupRun::factory()->create(['state' => BackupRun::STATE_PENDING, 'requested_by_user_id' => $admin->id]);

    expect(fn () => app(BackupService::class)->start($admin, BackupRun::TRIGGER_MANUAL))
        ->toThrow(OperationFailedException::class);
});

it('refuses to start while a rotation is running', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    KeyRotationRun::factory()->create(['status' => KeyRotationRun::STATUS_RUNNING, 'started_by_user_id' => $admin->id]);

    expect(fn () => app(BackupService::class)->start($admin, BackupRun::TRIGGER_MANUAL))
        ->toThrow(OperationFailedException::class);
});

it('refuses to start while an import is in flight', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    ImportRun::factory()->queued()->create(['requested_by_user_id' => $admin->id]);

    expect(fn () => app(BackupService::class)->start($admin, BackupRun::TRIGGER_MANUAL))
        ->toThrow(OperationFailedException::class);
});

it('refuses to start when no backup key is configured', function (): void {
    config(['vaultigen.backup_keyring' => null, 'vaultigen.backup_key_file' => '/nonexistent/backup.json']);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    expect(fn () => app(BackupService::class)->start($admin, BackupRun::TRIGGER_MANUAL))
        ->toThrow(OperationFailedException::class);

    // No run is created and no maintenance window is opened.
    expect(BackupRun::query()->count())->toBe(0)
        ->and(app(MaintenanceWindow::class)->active())->toBeFalse();
});

it('fails when a referenced object is missing', function (): void {
    $fixture = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($fixture['alice'], $fixture['vault']);
    $attachment = vaultigen_upload_attachment($fixture['alice'], $entry, 'bytes');
    Storage::disk('attachments')->delete($attachment->random_storage_path);

    $run = vaultigen_run_backup($fixture['admin']);

    expect($run->state)->toBe(BackupRun::STATE_FAILED)
        ->and($run->error_code)->toBe('object_missing');
});

it('reports an integrity failure when the manifest does not authenticate', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $cipher = Mockery::mock(BackupCipher::class, [app(FileStreamCipher::class)])->makePartial();
    $cipher->shouldReceive('verifyManifestMac')->once()->andReturnFalse();
    $this->app->instance(BackupCipher::class, $cipher);

    $run = vaultigen_run_backup($admin);

    expect($run->state)->toBe(BackupRun::STATE_FAILED)
        ->and($run->error_code)->toBe('integrity_failed');
});

it('reports a snapshot read failure', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $snapshot = Mockery::mock(DatabaseSnapshot::class)->makePartial();
    $snapshot->shouldReceive('estimatedBytes')->andReturn(0);
    $snapshot->shouldReceive('newTemporaryPath')->andReturn(sys_get_temp_dir().'/vaultigen-does-not-exist-'.Str::uuid().'/database');
    $snapshot->shouldReceive('dumpTo');
    $this->app->instance(DatabaseSnapshot::class, $snapshot);

    $run = vaultigen_run_backup($admin);

    expect($run->state)->toBe(BackupRun::STATE_FAILED)
        ->and($run->error_code)->toBe('snapshot_unreadable');
});

it('verifies an existing set and rejects a tampered snapshot or object', function (): void {
    $fixture = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($fixture['alice'], $fixture['vault']);
    vaultigen_upload_attachment($fixture['alice'], $entry, 'bytes');
    $run = vaultigen_run_backup($fixture['admin']);
    $directory = (string) $run->path;
    $service = app(BackupService::class);

    $service->verifySet($directory);
    expect(true)->toBeTrue();

    $manifest = json_decode((string) Storage::disk('local')->get($directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['snapshot']['checksum'] = str_repeat('a', 64);
    vaultigen_rewrite_manifest($directory, $manifest, (string) $run->id);
    expect(fn () => $service->verifySet($directory))->toThrow(OperationFailedException::class);

    Storage::disk('local')->delete($directory.'/database.enc');
    expect(fn () => $service->verifySet($directory))->toThrow(OperationFailedException::class);
});

it('rejects a tampered object checksum', function (): void {
    $fixture = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($fixture['alice'], $fixture['vault']);
    vaultigen_upload_attachment($fixture['alice'], $entry, 'bytes');
    $run = vaultigen_run_backup($fixture['admin']);
    $directory = (string) $run->path;

    $manifest = json_decode((string) Storage::disk('local')->get($directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['objects'][0]['checksum'] = str_repeat('a', 64);
    vaultigen_rewrite_manifest($directory, $manifest, (string) $run->id);

    expect(fn () => app(BackupService::class)->verifySet($directory))->toThrow(OperationFailedException::class);
});

function vaultigen_rewrite_manifest(string $directory, array $manifest, string $runId): void
{
    $canonical = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    Storage::disk('local')->put($directory.'/manifest.json', $canonical);
    Storage::disk('local')->put($directory.'/manifest.mac', app(BackupCipher::class)->manifestMac('backup-test-v1', str_repeat('b', 32), $runId, $canonical));
}

it('fails when the snapshot cannot be written', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $service = app(BackupService::class);
    $run = $service->start($admin, BackupRun::TRIGGER_MANUAL);
    Storage::disk('local')->makeDirectory('backups/'.$run->id.'/'.BackupService::DATABASE_OBJECT);

    $service->run($run);

    expect($run->refresh()->error_code)->toBe('snapshot_unreadable');
});

it('fails when an object cannot be written or read', function (): void {
    $fixture = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($fixture['alice'], $fixture['vault']);
    $attachment = vaultigen_upload_attachment($fixture['alice'], $entry, 'bytes');

    $service = app(BackupService::class);
    $run = $service->start($fixture['admin'], BackupRun::TRIGGER_MANUAL);
    Storage::disk('local')->makeDirectory('backups/'.$run->id.'/objects/'.hash('sha256', 'attachment:'.$attachment->id).'.enc');
    $service->run($run);
    expect($run->refresh()->error_code)->toBe('object_unwritable');

    $attachments = Mockery::mock(FilesystemAdapter::class);
    $attachments->shouldReceive('exists')->andReturnTrue();
    $attachments->shouldReceive('path')->andReturn(Storage::disk('attachments')->path(''));
    $attachments->shouldReceive('readStream')->andReturnFalse();
    Storage::set('attachments', $attachments);
    $second = $service->start($fixture['admin'], BackupRun::TRIGGER_MANUAL);
    $service->run($second);
    expect($second->refresh()->error_code)->toBe('object_unreadable');
});

it('copies an empty staged import object and counts it', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    ImportRun::factory()->create(['requested_by_user_id' => $admin->id, 'random_storage_path' => 'imports/empty.bin']);
    Storage::disk('attachments')->put('imports/empty.bin', '');

    $run = vaultigen_run_backup($admin);

    expect($run->state)->toBe(BackupRun::STATE_COMPLETED)
        ->and($run->object_count)->toBe(1);
});

it('reads a manifest and rejects a missing or malformed one', function (): void {
    $service = app(BackupService::class);

    expect(fn () => $service->readManifest('backups/absent'))->toThrow(RuntimeException::class);

    Storage::disk('local')->put('backups/broken/manifest.json', 'not-json');
    expect(fn () => $service->readManifest('backups/broken'))->toThrow(JsonException::class);

    Storage::disk('local')->put('backups/array/manifest.json', '"scalar"');
    expect(fn () => $service->readManifest('backups/array'))->toThrow(RuntimeException::class);
});

it('fails a backup safely when a storage operation silently fails', function (string $method, string $suffix, string $code): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $admin);
    $entry = vaultigen_make_entry($admin, $vault);
    $attachment = vaultigen_upload_attachment($admin, $entry, 'preserve these bytes');
    if ($method === 'deleteDirectory') {
        Storage::disk('attachments')->delete($attachment->random_storage_path);
    }
    FakeBackupRuntime::refuseStorage($method, fn (string $path): bool => $suffix === '' || str_ends_with($path, $suffix));

    $run = vaultigen_run_backup($admin);

    expect($run->state)->toBe(BackupRun::STATE_FAILED);
    expect($run->error_code)->toBe($code);
    expect(app(MaintenanceWindow::class)->active())->toBeFalse();
    if ($method !== 'deleteDirectory') {
        Storage::disk('local')->assertMissing('backups/'.$run->id.'/manifest.json');
    }
})->with([
    'backup directory' => ['makeDirectory', '', 'object_unwritable'],
    'object directory' => ['makeDirectory', '/objects', 'object_unwritable'],
    'manifest authentication file' => ['put', '/manifest.mac', 'object_unwritable'],
    'failed-run cleanup' => ['deleteDirectory', '', 'backup_cleanup_failed'],
]);

it('fails and cleans up a backup when an object stream cannot be read', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $admin);
    $entry = vaultigen_make_entry($admin, $vault);
    vaultigen_upload_attachment($admin, $entry, 'bytes');
    $disk = Storage::disk('attachments');
    $mock = Mockery::mock(FilesystemAdapter::class, [$disk->getDriver(), $disk->getAdapter(), $disk->getConfig()])->makePartial();
    $mock->shouldReceive('readStream')->andReturnUsing(fn () => fopen('vaultigen-failread://object', 'rb'));
    Storage::set('attachments', $mock);

    $run = vaultigen_run_backup($admin);

    expect($run->error_code)->toBe('object_unreadable');
    Storage::disk('local')->assertMissing('backups/'.$run->id.'/database.enc');
});

it('fails and cleans up a backup after a short object write', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $admin);
    $entry = vaultigen_make_entry($admin, $vault);
    vaultigen_upload_attachment($admin, $entry, 'bytes');
    FakeBackupRuntime::$overrides['fwrite'] = fn ($stream, string $data): int|false => str_contains(stream_get_meta_data($stream)['uri'], '/objects/') ? 0 : fwrite($stream, $data);

    $run = vaultigen_run_backup($admin);

    expect($run->error_code)->toBe('object_unwritable');
    Storage::disk('local')->assertMissing('backups/'.$run->id.'/database.enc');
});
