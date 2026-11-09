<?php

use App\Models\BackupRun;
use App\Models\ImportRun;
use App\Services\Operations\BackupService;
use App\Services\Operations\MaintenanceWindow;
use Illuminate\Support\Facades\Storage;

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

it('creates a verified backup set with a manifest and objects', function (): void {
    $fixture = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($fixture['alice'], $fixture['vault']);
    $attachment = vaultigen_upload_attachment($fixture['alice'], $entry, 'attachment-bytes');

    $service = app(BackupService::class);
    $run = $service->start($fixture['admin'], BackupRun::TRIGGER_MANUAL);
    expect($run->state)->toBe(BackupRun::STATE_PENDING);

    $service->run($run);
    $run->refresh();

    expect($run->state)->toBe(BackupRun::STATE_COMPLETED)
        ->and($run->object_count)->toBe(1)
        ->and($run->manifest_checksum)->not->toBeNull();

    Storage::disk('local')->assertExists($run->path.'/manifest.json');
    Storage::disk('local')->assertExists($run->path.'/manifest.mac');
    Storage::disk('local')->assertExists($run->path.'/database.enc');
    Storage::disk('local')->assertExists($run->path.'/objects/'.hash('sha256', 'attachment:'.$attachment->id).'.enc');

    $manifest = json_decode((string) Storage::disk('local')->get($run->path.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($manifest['backup_run_id'])->toBe($run->id)
        ->and($manifest['installation_id'])->toBe(config('vaultigen.installation_id'))
        ->and($manifest['backup_key_id'])->toBe('backup-test-v1')
        ->and($manifest['objects'])->toHaveCount(1)
        ->and($manifest['required_key_ids']['kek'])->toContain('kek-test-v1');
});

it('runs a backup only once when invoked twice', function (): void {
    $fixture = vaultigen_entry_fixture();
    $service = app(BackupService::class);
    $run = $service->start($fixture['admin'], BackupRun::TRIGGER_MANUAL);

    $service->run($run);
    $service->run($run->refresh());

    expect(BackupRun::query()->where('state', BackupRun::STATE_COMPLETED)->count())->toBe(1);
});

it('fails a backup when an import was queued after the backup was requested', function (): void {
    $fixture = vaultigen_entry_fixture();
    $service = app(BackupService::class);
    $run = $service->start($fixture['admin'], BackupRun::TRIGGER_MANUAL);
    ImportRun::factory()->create(['requested_by_user_id' => $fixture['admin']->id, 'status' => ImportRun::STATUS_QUEUED]);

    $service->run($run);

    expect($run->refresh()->state)->toBe(BackupRun::STATE_FAILED)
        ->and($run->error_code)->toBe('import_in_flight')
        ->and(app(MaintenanceWindow::class)->active())->toBeFalse();
});
