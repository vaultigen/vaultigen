<?php

use App\Jobs\RunBackup;
use App\Models\BackupRun;
use App\Models\FileAttachment;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\EntryVault;
use App\Services\Imports\ImportSourceStore;
use App\Services\Operations\BackupCipher;
use App\Services\Operations\BackupKeyRotationService;
use App\Services\Operations\BackupPolicy;
use App\Services\Operations\BackupService;
use App\Services\Operations\BackupStorage;
use App\Services\Operations\CommandRunner;
use App\Services\Operations\DatabaseSnapshot;
use App\Services\Operations\MaintenanceWindow;
use App\Services\Operations\OperationFailedException;
use App\Services\Operations\OperationLock;
use App\Services\Operations\OperationsStatus;
use App\Services\Operations\RestoreService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('attachments');
    config([
        'vaultigen.backup_root' => Storage::disk('local')->path('custom-sets'),
        'vaultigen.backup_keyring' => json_encode([
            'active_key_id' => 'backup-test-v1',
            'keys' => ['backup-test-v1' => base64_encode(str_repeat('b', 32))],
        ], JSON_THROW_ON_ERROR),
    ]);
});

function backup_recovery_offline(): void
{
    $mode = Mockery::mock(MaintenanceMode::class);
    $mode->shouldReceive('active')->andReturnTrue();
    app()->instance(MaintenanceMode::class, $mode);
}

it('creates verifies and rotates a set entirely in the custom root while accepting legacy row paths', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = vaultigen_run_backup($admin);
    $storage = app(BackupStorage::class);
    $service = app(BackupService::class);
    $service->verifySet((string) $run->path);
    $service->verifySet((string) $run->id);

    expect($run->state)->toBe(BackupRun::STATE_COMPLETED);
    Storage::disk('local')->assertMissing('backups/'.$run->id.'/manifest.json');
    Storage::disk('local')->assertExists('custom-sets/'.$run->id.'/manifest.json');
    expect(app(OperationsStatus::class)->readiness()[1]['state'])->toBe('healthy');

    app(BackupKeyRotationService::class)->rewrap((string) $run->path, 'backup-test-v1', str_repeat('b', 32), 'backup-test-v2', str_repeat('c', 32));
    config(['vaultigen.backup_keyring' => json_encode([
        'active_key_id' => 'backup-test-v2', 'keys' => ['backup-test-v2' => base64_encode(str_repeat('c', 32))],
    ], JSON_THROW_ON_ERROR)]);
    $this->artisan('vaultigen:backup:verify', ['directory' => $storage->path((string) $run->path)])->assertSuccessful();
    expect(app(BackupService::class)->readManifest((string) $run->path)['backup_key_id'])->toBe('backup-test-v2');
});

it('cleans a failed custom-root set and its private temporary snapshot', function (): void {
    $fixture = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($fixture['alice'], $fixture['vault']);
    $attachment = vaultigen_upload_attachment($fixture['alice'], $entry, 'lost');
    Storage::disk('attachments')->delete($attachment->random_storage_path);
    $before = glob(config('vaultigen.backup_temporary_root').'/snapshot-*');

    $run = vaultigen_run_backup($fixture['admin']);

    expect($run->state)->toBe(BackupRun::STATE_FAILED);
    expect(is_dir(app(BackupStorage::class)->path((string) $run->id)))->toBeFalse();
    expect(glob(config('vaultigen.backup_temporary_root').'/snapshot-*'))->toBe($before);
});

it('reports readiness failure when the adapter silently refuses writes', function (): void {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->andReturnFalse();
    $disk->shouldReceive('delete')->andReturnTrue();
    Storage::shouldReceive('build')->andReturn($disk);

    expect(app(OperationsStatus::class)->readiness()[1]['state'])->toBe('error');
});

it('never completes a backup when a manifest write silently fails', function (): void {
    $real = app(BackupStorage::class)->disk();
    $disk = Mockery::mock(FilesystemAdapter::class)->makePartial();
    $disk->shouldReceive('makeDirectory')->andReturnUsing(fn (string $path): bool => $real->makeDirectory($path));
    $disk->shouldReceive('path')->andReturnUsing(fn (string $path): string => $real->path($path));
    $disk->shouldReceive('put')->andReturnFalse();
    $disk->shouldReceive('deleteDirectory')->andReturnUsing(fn (string $path): bool => $real->deleteDirectory($path));
    Storage::shouldReceive('build')->andReturn($disk);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $run = vaultigen_run_backup($admin);

    expect($run->state)->toBe(BackupRun::STATE_FAILED);
    expect(is_dir($real->path((string) $run->id)))->toBeFalse();
});

it('restores secrets attachments and retained import sources onto an empty SQLite database and private disk', function (int $format): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite round trip; engine round trip is tested separately.');
    }
    $fixture = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($fixture['alice'], $fixture['vault']);
    $attachment = vaultigen_upload_attachment($fixture['alice'], $entry, 'recovered attachment');
    $import = ImportRun::factory()->create(['requested_by_user_id' => $fixture['admin']->id]);
    $import->forceFill(app(ImportSourceStore::class)->seal($import->id, '{"synthetic":"retained source"}'))->save();
    $run = vaultigen_run_backup($fixture['admin']);
    $directory = app(BackupStorage::class)->path((string) $run->path);
    if ($format === 1) {
        $manifest = app(BackupService::class)->readManifest((string) $run->path);
        $manifest['format_version'] = 1;
        unset($manifest['recovery_checks']);
        $raw = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($directory.'/manifest.json', $raw);
        file_put_contents($directory.'/manifest.mac', app(BackupCipher::class)->manifestMac('backup-test-v1', str_repeat('b', 32), $run->id, $raw));
    }
    Storage::fake('attachments');
    $target = Storage::disk('local')->path('empty.sqlite');
    $originalPdo = DB::connection()->getPdo();
    config(['database.connections.sqlite.database' => $target]);
    backup_recovery_offline();

    try {
        app(RestoreService::class)->restore($directory, true);

        expect(app(EntryVault::class)->reveal($fixture['alice'], $entry->id, 'password'))->toBe('correct-horse-battery-staple-42');
        $this->actingAs($fixture['alice']);
        $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);
        expect($this->get(route('attachments.download', ['attachment' => $attachment->id]))->streamedContent())->toBe('recovered attachment');
        expect(app(ImportSourceStore::class)->open(ImportRun::query()->findOrFail($import->id)))->toBe('{"synthetic":"retained source"}');
        $this->assertDatabaseHas('audit_events', ['action' => 'restore.completed']);
        expect(is_file(storage_path('framework/restore-in-progress.json')))->toBeFalse();
    } finally {
        DB::purge();
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::connection()->setPdo($originalPdo);
    }
})->with(['legacy format' => [1], 'current format' => [2]]);

it('rejects wrong recovery values before changing the database or files', function (string $key, string $value): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = vaultigen_run_backup($admin);
    $directory = app(BackupStorage::class)->path((string) $run->path);
    config([$key => $value]);
    $count = User::query()->count();

    expect(fn () => app(RestoreService::class)->preflight($directory))->toThrow(OperationFailedException::class);

    expect(User::query()->count())->toBe($count);
    Storage::disk('attachments')->assertEmpty();
})->with([
    'framework key' => ['app.key', 'base64:'.base64_encode(str_repeat('x', 32))],
    'audit key' => ['vaultigen.audit_key', base64_encode(str_repeat('x', 32))],
    'passkey secret' => ['vaultigen.passkey_secret', base64_encode(str_repeat('x', 32))],
    'KEK with matching identifier' => ['vaultigen.kek_keyring', '{"active_key_id":"kek-test-v1","keys":{"kek-test-v1":"eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHg="}}'],
]);

it('rejects unsafe authenticated manifests without changing the target', function (string $field, mixed $value): void {
    [$directory, $manifest] = vaultigen_fake_backup_set();
    if (str_starts_with($field, 'object.')) {
        $manifest['objects'][0][substr($field, 7)] = $value;
    } else {
        $manifest[$field] = $value;
    }
    $raw = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($directory.'/manifest.json', $raw);
    file_put_contents($directory.'/manifest.mac', app(BackupCipher::class)->manifestMac('backup-test-v1', str_repeat('b', 32), $manifest['backup_run_id'], $raw));

    expect(fn () => app(RestoreService::class)->preflight($directory))->toThrow(OperationFailedException::class);
    Storage::disk('attachments')->assertEmpty();
})->with([
    'absolute destination' => ['object.source_path', '/tmp/escape'],
    'destination traversal' => ['object.source_path', '../escape'],
    'source traversal' => ['object.file', 'objects/../../escape'],
    'stream wrapper' => ['object.source_path', 'php://filter'],
    'unknown kind' => ['object.kind', 'configuration'],
    'unsupported format' => ['format_version', 99],
    'different engine' => ['database_driver', 'sqlsrv'],
    'malformed inventory' => ['objects', 'invalid'],
]);

it('rejects linked source and destination files', function (bool $destination): void {
    [$directory, $manifest] = vaultigen_fake_backup_set();
    $outside = Storage::disk('local')->path('outside');
    file_put_contents($outside, 'object-ciphertext');
    $link = $destination ? Storage::disk('attachments')->path('x') : $directory.'/objects/a.enc';
    @unlink($link);
    symlink($outside, $link);

    expect(fn () => app(RestoreService::class)->preflight($directory))->toThrow(OperationFailedException::class);
    expect(file_get_contents($outside))->toBe('object-ciphertext');
})->with([true, false]);

it('requires offline exclusive access before applying a restore', function (): void {
    [$directory] = vaultigen_fake_backup_set();

    expect(fn () => app(RestoreService::class)->restore($directory))->toThrow(OperationFailedException::class);
    Storage::disk('attachments')->assertEmpty();
});

it('keeps the installation offline and removes plaintext staging when database application is interrupted', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $run = vaultigen_run_backup($admin);
    $directory = app(BackupStorage::class)->path((string) $run->path);
    $snapshot = Mockery::mock(DatabaseSnapshot::class, [app(CommandRunner::class)])->makePartial();
    $snapshot->shouldReceive('restoreFrom')->once()->andThrow(new RuntimeException('interrupted'));
    app()->instance(DatabaseSnapshot::class, $snapshot);
    backup_recovery_offline();
    if (DB::connection()->getDriverName() === 'sqlite') {
        config(['database.connections.sqlite.database' => Storage::disk('local')->path('interrupted.sqlite')]);
    }

    $stagingBefore = glob(config('vaultigen.backup_temporary_root').'/snapshot-*');
    try {
        expect(fn () => app(RestoreService::class)->restore($directory, true))->toThrow(OperationFailedException::class);
        expect(is_file(storage_path('framework/restore-in-progress.json')))->toBeTrue();
        expect(glob(config('vaultigen.backup_temporary_root').'/snapshot-*'))->toBe($stagingBefore);
        expect(app()->isDownForMaintenance())->toBeTrue();
        $this->assertDatabaseMissing('audit_events', ['action' => 'restore.completed']);
    } finally {
        @unlink(storage_path('framework/restore-in-progress.json'));
    }
});

it('queues configured scheduled backups and records conflicts as failed attempts', function (): void {
    Queue::fake([RunBackup::class]);
    config(['vaultigen.backup_schedule_enabled' => true, 'queue.default' => 'database', 'queue.connections.database.retry_after' => 3660]);

    $this->artisan('vaultigen:backup', ['--scheduled' => true])->assertSuccessful();

    $run = BackupRun::query()->where('state', BackupRun::STATE_PENDING)->sole();
    expect($run->trigger)->toBe(BackupRun::TRIGGER_SCHEDULED);
    Queue::assertPushed(RunBackup::class, fn (RunBackup $job): bool => $job->backupRunId === $run->id);
    $this->artisan('vaultigen:backup', ['--scheduled' => true])->assertFailed();
    $this->assertDatabaseHas('backup_runs', ['state' => 'failed', 'trigger' => 'scheduled', 'error_code' => 'backup_in_progress']);
});

it('leaves scheduled execution disabled by default', function (): void {
    Queue::fake([RunBackup::class]);

    $this->artisan('vaultigen:backup', ['--scheduled' => true])->assertSuccessful();

    Queue::assertNothingPushed();
    $this->assertDatabaseCount('backup_runs', 0);
});

it('rejects invalid backup schedule configuration', function (string $key, mixed $value): void {
    config(['vaultigen.'.$key => $value]);

    expect(fn () => app(BackupPolicy::class)->validate())->toThrow(OperationFailedException::class);
})->with([
    ['backup_schedule_enabled', 'perhaps'], ['backup_schedule', 'not cron'],
    ['backup_timezone', 'Atlantis/Never'], ['backup_retention_days', 0],
]);

it('refuses stale-run recovery and failure callbacks while another process holds the operation lock', function (): void {
    $run = BackupRun::factory()->create(['state' => BackupRun::STATE_RUNNING]);
    app(MaintenanceWindow::class)->begin('backup', (string) $run->id, 'test');
    backup_recovery_offline();
    $owner = new Process([PHP_BINARY, '-r', '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); echo "locked"; sleep(30);', '--', storage_path('framework/backup-operations.lock')]);
    $owner->start();
    $owner->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'locked'));
    try {
        $this->artisan('vaultigen:backup:recover', ['--services-stopped' => true])->assertFailed();
        (new RunBackup((string) $run->id))->failed(new RuntimeException('timeout'));
        expect($run->refresh()->state)->toBe(BackupRun::STATE_RUNNING);
        expect(app(MaintenanceWindow::class)->active())->toBeTrue();
    } finally {
        $owner->stop(0);
    }
    $this->artisan('vaultigen:backup:recover', ['--services-stopped' => true])->assertSuccessful();
    expect($run->refresh()->state)->toBe(BackupRun::STATE_FAILED);
    expect(app(MaintenanceWindow::class)->active())->toBeFalse();
});

it('fails a timed-out run from the failure callback in the process that still holds the lock', function (): void {
    $run = BackupRun::factory()->create(['state' => BackupRun::STATE_RUNNING]);
    app(MaintenanceWindow::class)->begin('backup', (string) $run->id, 'test');
    $abandoned = app(DatabaseSnapshot::class)->newTemporaryPath();
    file_put_contents($abandoned, 'plaintext dump');
    $lock = new OperationLock;
    $lock->acquire();
    try {
        (new RunBackup((string) $run->id))->failed(new RuntimeException('timeout'));
    } finally {
        $lock->release();
    }

    expect($run->refresh()->state)->toBe(BackupRun::STATE_FAILED);
    expect($run->error_code)->toBe('queue_failed');
    expect(app(MaintenanceWindow::class)->active())->toBeFalse();
    expect(is_dir(dirname($abandoned)))->toBeFalse();
});

it('never closes another operations maintenance window from a failed backup callback', function (): void {
    $run = BackupRun::factory()->create(['state' => BackupRun::STATE_RUNNING]);
    $operationId = (string) Str::uuid();
    app(MaintenanceWindow::class)->begin('rotation', $operationId, 'test');

    (new RunBackup((string) $run->id))->failed(new RuntimeException('failed'));

    expect(app(MaintenanceWindow::class)->current()['operation_id'])->toBe($operationId);
});

it('prunes only expired completed verified sets and preserves the last good restore point', function (): void {
    $this->freezeTime();
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $old = vaultigen_run_backup($admin);
    $this->travel(1)->seconds();
    $latest = vaultigen_run_backup($admin);
    $this->travel(40)->days();
    $this->artisan('vaultigen:backups:prune')->assertSuccessful();
    expect(is_dir(app(BackupStorage::class)->path((string) $old->path)))->toBeTrue();
    config(['vaultigen.backup_retention_enabled' => true]);

    $this->artisan('vaultigen:backups:prune')->assertSuccessful();

    expect($old->refresh()->path)->toBeNull();
    expect(is_dir(app(BackupStorage::class)->path((string) $latest->path)))->toBeTrue();
    app(BackupService::class)->verifySet((string) $latest->path);
});

it('restores a native database dump and encrypted files into an empty supported server database', function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Covered by the SQLite round trip.');
    }
    $fixture = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($fixture['alice'], $fixture['vault']);
    $attachment = vaultigen_upload_attachment($fixture['alice'], $entry, 'server database attachment');
    $import = ImportRun::factory()->create(['requested_by_user_id' => $fixture['admin']->id]);
    $import->forceFill(app(ImportSourceStore::class)->seal($import->id, 'server import source'))->save();
    $run = vaultigen_run_backup($fixture['admin']);
    expect($run->state)->toBe(BackupRun::STATE_COMPLETED);
    $directory = app(BackupStorage::class)->path((string) $run->path);
    Storage::fake('attachments');
    backup_recovery_offline();
    DB::connection()->getSchemaBuilder()->dropAllTables();

    app(RestoreService::class)->restore($directory, true);

    expect(app(EntryVault::class)->reveal($fixture['alice'], $entry->id, 'password'))->toBe('correct-horse-battery-staple-42');
    expect(vaultigen_decrypt_attachment(FileAttachment::query()->findOrFail($attachment->id)))->toBe('server database attachment');
    expect(app(ImportSourceStore::class)->open(ImportRun::query()->findOrFail($import->id)))->toBe('server import source');
    expect(app(AuditWriter::class)->verify())->toBeTrue();
    $this->assertDatabaseHas('audit_events', ['action' => 'restore.completed']);
});

it('prevents bringing a partially restored installation online or starting another backup', function (): void {
    $marker = storage_path('framework/restore-in-progress.json');
    file_put_contents($marker, '{}');
    try {
        expect(fn () => app(BackupService::class)->start(null, BackupRun::TRIGGER_COMMAND))->toThrow(OperationFailedException::class);
        expect(fn () => Event::dispatch(new CommandStarting('up', new ArrayInput([]), new BufferedOutput)))->toThrow(RuntimeException::class);
        $this->assertDatabaseCount('backup_runs', 0);
    } finally {
        unlink($marker);
    }
});

it('registers the configured backup schedule at the requested time and omits it when disabled', function (): void {
    config(['vaultigen.backup_schedule_enabled' => true, 'vaultigen.backup_schedule' => '15 4 * * *', 'vaultigen.backup_timezone' => 'Europe/Berlin']);
    $this->travelTo(CarbonImmutable::parse('2026-01-15 03:15:00', 'UTC'));
    Schedule::swap(new Illuminate\Console\Scheduling\Schedule);
    require base_path('routes/console.php');
    $events = collect(Schedule::events())->filter(fn ($event): bool => str_contains($event->command ?? '', 'vaultigen:backup --scheduled'));
    expect($events)->toHaveCount(1);
    expect($events->first()->isDue(app()))->toBeTrue();
    expect($events->first()->runInBackground)->toBeTrue();

    config(['vaultigen.backup_schedule_enabled' => false]);
    Schedule::swap(new Illuminate\Console\Scheduling\Schedule);
    require base_path('routes/console.php');
    expect(collect(Schedule::events())->filter(fn ($event): bool => str_contains($event->command ?? '', 'vaultigen:backup --scheduled')))->toBeEmpty();
});

it('fails safely when there is insufficient staging and destination headroom', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    config(['vaultigen.storage.min_free_bytes' => PHP_INT_MAX]);

    $run = vaultigen_run_backup($admin);

    expect($run->state)->toBe(BackupRun::STATE_FAILED);
    expect($run->error_code)->toBe('insufficient_space');
    expect(app(MaintenanceWindow::class)->active())->toBeFalse();
    expect(is_dir(app(BackupStorage::class)->path((string) $run->id)))->toBeFalse();
});

it('leaves the target untouched when a stored-file destination cannot be replaced', function (): void {
    $fixture = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($fixture['alice'], $fixture['vault']);
    $attachment = vaultigen_upload_attachment($fixture['alice'], $entry, 'original');
    $run = vaultigen_run_backup($fixture['admin']);
    $directory = app(BackupStorage::class)->path((string) $run->path);
    Storage::fake('attachments');
    Storage::disk('attachments')->makeDirectory($attachment->random_storage_path);
    backup_recovery_offline();

    expect(fn () => app(RestoreService::class)->restore($directory, true))->toThrow(OperationFailedException::class);

    $this->assertDatabaseHas('entries', ['id' => $entry->id]);
    $this->assertDatabaseMissing('audit_events', ['action' => 'restore.completed']);
    expect(is_file(storage_path('framework/restore-in-progress.json')))->toBeFalse();
});

it('removes staging when file application fails and permits a complete retry while staying offline', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite target used to isolate cross-resource failure.');
    }
    $fixture = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($fixture['alice'], $fixture['vault']);
    $attachment = vaultigen_upload_attachment($fixture['alice'], $entry, 'retry file');
    $run = vaultigen_run_backup($fixture['admin']);
    $directory = app(BackupStorage::class)->path((string) $run->path);
    Storage::fake('attachments');
    $destination = Storage::disk('attachments')->path($attachment->random_storage_path);
    $originalPdo = DB::connection()->getPdo();
    config(['database.connections.sqlite.database' => Storage::disk('local')->path('file-failure.sqlite')]);
    backup_recovery_offline();
    $real = new DatabaseSnapshot(app(CommandRunner::class));
    $snapshot = Mockery::mock(DatabaseSnapshot::class, [app(CommandRunner::class)])->makePartial();
    $snapshot->shouldReceive('restoreFrom')->once()->andReturnUsing(function (string $path) use ($real, $destination): void {
        $real->restoreFrom($path);
        mkdir($destination, 0700, true);
    });
    app()->instance(DatabaseSnapshot::class, $snapshot);
    $stagingBefore = glob(config('vaultigen.backup_temporary_root').'/snapshot-*');
    try {
        expect(fn () => app(RestoreService::class)->restore($directory, true))->toThrow(OperationFailedException::class);
        expect(is_file(storage_path('framework/restore-in-progress.json')))->toBeTrue();
        expect(glob(config('vaultigen.backup_temporary_root').'/snapshot-*'))->toBe($stagingBefore);
        $this->assertDatabaseMissing('audit_events', ['action' => 'restore.completed']);
        rmdir($destination);
        app()->instance(DatabaseSnapshot::class, $real);

        app(RestoreService::class)->restore($directory, true);

        expect(vaultigen_decrypt_attachment(FileAttachment::query()->findOrFail($attachment->id)))->toBe('retry file');
        expect(app()->isDownForMaintenance())->toBeTrue();
        expect(is_file(storage_path('framework/restore-in-progress.json')))->toBeFalse();
    } finally {
        @unlink(storage_path('framework/restore-in-progress.json'));
        DB::purge();
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::connection()->setPdo($originalPdo);
    }
});

it('keeps every other scheduled task registered when backup settings are invalid', function (): void {
    config(['vaultigen.backup_schedule_enabled' => true, 'vaultigen.backup_timezone' => 'Atlantis/Never']);
    Schedule::swap(new Illuminate\Console\Scheduling\Schedule);

    require base_path('routes/console.php');

    $commands = collect(Schedule::events())->map(fn ($event): string => (string) $event->command);
    expect($commands->filter(fn (string $command): bool => str_contains($command, 'vaultigen:backup')))->toBeEmpty();
    expect($commands->filter(fn (string $command): bool => str_contains($command, 'vaultigen:heartbeat')))->toHaveCount(1);
    expect(app(OperationsStatus::class)->backupHealth()['code'])->toBe('backup_configuration_invalid');
});

it('creates verifies and restores sets when configured roots sit below symlinked directories', function (): void {
    $real = Storage::disk('local')->path('release-1');
    mkdir($real.'/shared', 0700, true);
    symlink($real, Storage::disk('local')->path('current'));
    config([
        'vaultigen.backup_root' => Storage::disk('local')->path('current/shared/sets'),
        'vaultigen.backup_temporary_root' => Storage::disk('local')->path('current/shared/work'),
    ]);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $run = vaultigen_run_backup($admin);

    expect($run->state)->toBe(BackupRun::STATE_COMPLETED);
    expect(is_file($real.'/shared/sets/'.$run->id.'/manifest.json'))->toBeTrue();
    app(BackupService::class)->verifySet((string) $run->path);
    app(RestoreService::class)->preflight(Storage::disk('local')->path('current/shared/sets/'.$run->id));
});
