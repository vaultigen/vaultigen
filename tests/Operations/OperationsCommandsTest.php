<?php

use App\Models\BackupRun;
use App\Models\KeyRotationRun;
use App\Models\User;
use App\Services\Operations\MaintenanceWindow;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Bus;
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

it('creates a backup from the console', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    vaultigen_entry_fixture();

    $this->artisan('vaultigen:backup')->assertSuccessful();

    expect(BackupRun::query()->where('state', BackupRun::STATE_COMPLETED)->count())->toBe(1)
        ->and(BackupRun::query()->first()?->requested_by_user_id)->toBeNull();
});

it('fails the backup command when a conflict exists', function (): void {
    app(MaintenanceWindow::class)->begin('backup', null, 'test');

    $this->artisan('vaultigen:backup')->assertFailed();
});

it('restores a backup from the console', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite-specific test.');
    }

    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    vaultigen_entry_fixture();
    $run = vaultigen_run_backup($admin);

    $target = tempnam(sys_get_temp_dir(), 'vaultigen-restore-');
    @unlink($target);
    config(['database.connections.sqlite.database' => $target]);

    $mode = Mockery::mock(MaintenanceMode::class);
    $mode->shouldReceive('active')->andReturnTrue();
    app()->instance(MaintenanceMode::class, $mode);
    $this->artisan('vaultigen:restore', ['directory' => vaultigen_backup_directory($run), '--force' => true, '--services-stopped' => true])
        ->assertSuccessful();

    expect(is_file($target))->toBeTrue();
    @unlink($target);
});

it('fails the restore command when preflight fails', function (): void {
    $this->artisan('vaultigen:restore', ['directory' => '/tmp/vaultigen-absent', '--force' => true])
        ->expectsOutputToContain('Restore preflight failed')
        ->assertFailed();
});

it('rewraps installation keys from the console', function (): void {
    vaultigen_entry_fixture();
    config(['vaultigen.kek_keyring' => json_encode([
        'active_key_id' => 'kek-test-v2',
        'keys' => [
            'kek-test-v1' => base64_encode(str_repeat('k', 32)),
            'kek-test-v2' => base64_encode(str_repeat('j', 32)),
        ],
    ], JSON_THROW_ON_ERROR)]);

    $this->artisan('vaultigen:rotate-kek')->assertSuccessful();

    expect(KeyRotationRun::query()->where('status', KeyRotationRun::STATUS_COMPLETED)->count())->toBe(1);
});

it('reports nothing to rotate from the console', function (): void {
    vaultigen_entry_fixture();

    $this->artisan('vaultigen:rotate-kek')->assertFailed();
});

it('rotates a vault key from the console', function (string $identifier): void {
    ['alice' => $alice, 'vault' => $vault, 'admin' => $admin] = vaultigen_entry_fixture();
    vaultigen_make_entry($alice, $vault);
    BackupRun::factory()->completed()->create(['requested_by_user_id' => $admin->id]);

    $this->artisan('vaultigen:rotate-vault-key', ['vault' => $vault->{$identifier}])->assertSuccessful();

    expect(KeyRotationRun::query()->where('status', KeyRotationRun::STATUS_COMPLETED)->count())->toBe(1);
})->with(['id', 'slug']);

it('rejects an unknown vault from the console', function (): void {
    $this->artisan('vaultigen:rotate-vault-key', ['vault' => 'nope'])->assertFailed();
});

it('resumes, or reports, a rotation from the console', function (): void {
    ['vault' => $vault, 'admin' => $admin] = vaultigen_entry_fixture();

    $completed = KeyRotationRun::factory()->create([
        'operation' => KeyRotationRun::OPERATION_VAULT_ROTATE,
        'vault_id' => $vault->id,
        'status' => KeyRotationRun::STATUS_COMPLETED,
        'started_by_user_id' => $admin->id,
    ]);
    $this->artisan('vaultigen:rotation:resume', ['run' => $completed->id])->assertSuccessful();

    $this->artisan('vaultigen:rotation:resume', ['run' => (string) Str::uuid()])->assertFailed();

    $failed = KeyRotationRun::factory()->create([
        'operation' => 'bogus',
        'vault_id' => $vault->id,
        'status' => KeyRotationRun::STATUS_FAILED,
        'started_by_user_id' => $admin->id,
    ]);
    $this->artisan('vaultigen:rotation:resume', ['run' => $failed->id])->assertFailed();
});

it('fails the backup command when no backup key is configured', function (): void {
    config(['vaultigen.backup_keyring' => null, 'vaultigen.backup_key_file' => '/nonexistent/backup.json']);

    $this->artisan('vaultigen:backup')->assertFailed();
});

it('reports a failed backup run from the command', function (): void {
    $fixture = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($fixture['alice'], $fixture['vault']);
    $attachment = vaultigen_upload_attachment($fixture['alice'], $entry, 'bytes');
    Storage::disk('attachments')->delete($attachment->random_storage_path);

    $this->artisan('vaultigen:backup')->assertFailed();
});

it('fails the installation rewrap command when a wrapping cannot be unwrapped', function (): void {
    vaultigen_entry_fixture();
    config(['vaultigen.kek_keyring' => json_encode([
        'active_key_id' => 'kek-test-v2',
        'keys' => ['kek-test-v2' => base64_encode(str_repeat('j', 32))],
    ], JSON_THROW_ON_ERROR)]);

    $this->artisan('vaultigen:rotate-kek')->assertFailed();

    expect(KeyRotationRun::query()->where('error_code', 'rotation_failed')->count())->toBeGreaterThan(0);
});

it('fails the vault rotation command without a completed backup', function (): void {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    vaultigen_make_entry($alice, $vault);

    $this->artisan('vaultigen:rotate-vault-key', ['vault' => $vault->id])->assertFailed();
});

it('fails the vault rotation command while a maintenance window is open', function (): void {
    ['vault' => $vault] = vaultigen_entry_fixture();
    app(MaintenanceWindow::class)->begin('backup', null, 'test');

    $this->artisan('vaultigen:rotate-vault-key', ['vault' => $vault->id])->assertFailed();
});

it('cancels a restore without confirmation', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    vaultigen_entry_fixture();
    $run = vaultigen_run_backup($admin);

    $this->artisan('vaultigen:restore', ['directory' => vaultigen_backup_directory($run)])
        ->expectsConfirmation((string) __('operations.restore.confirm'), 'no')
        ->assertFailed();
});

it('fails the restore command when the restore step fails', function (): void {
    [$directory] = vaultigen_fake_backup_set();

    $this->artisan('vaultigen:restore', ['directory' => $directory, '--force' => true])
        ->expectsOutputToContain('Restore failed')
        ->assertFailed();
});

it('skips scheduled maintenance commands while a window is open', function (): void {
    app(MaintenanceWindow::class)->begin('backup', null, 'test');

    $this->artisan('vaultigen:purge-trash')->assertSuccessful();
    $this->artisan('vaultigen:cleanup-imports')->assertSuccessful();
    $this->artisan('vaultigen:prune-audit')->assertSuccessful();
});

it('marks a scheduled backup failed when queue dispatch fails', function (): void {
    config(['vaultigen.backup_schedule_enabled' => true, 'queue.default' => 'database']);
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));

    $this->artisan('vaultigen:backup', ['--scheduled' => true])->assertFailed();

    $this->assertDatabaseHas('backup_runs', ['state' => 'failed', 'trigger' => 'scheduled', 'error_code' => 'queue_failed']);
    expect(app(MaintenanceWindow::class)->active())->toBeFalse();
});

it('refuses scheduled backups with an unsafe queue configuration', function (string $connection, int $retryAfter): void {
    config(['vaultigen.backup_schedule_enabled' => true, 'queue.default' => $connection, 'queue.connections.database.retry_after' => $retryAfter]);

    $this->artisan('vaultigen:backup', ['--scheduled' => true])->assertFailed();

    $this->assertDatabaseHas('backup_runs', ['state' => 'failed', 'error_code' => 'backup_queue_invalid']);
})->with(['synchronous queue' => ['sync', 3660], 'premature redelivery' => ['database', 3600]]);

it('preserves recent and unverifiable sets during retention', function (): void {
    $this->freezeTime();
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $first = vaultigen_run_backup($admin);
    $this->travel(1)->seconds();
    $second = vaultigen_run_backup($admin);
    $broken = BackupRun::factory()->completed()->create(['path' => 'backups/broken', 'completed_at' => now()->addSecond()]);
    Storage::disk('local')->put('backups/broken/manifest.json', 'corrupt');
    config(['vaultigen.backup_retention_enabled' => true]);

    $this->artisan('vaultigen:backups:prune')->assertSuccessful();

    foreach ([$first, $second, $broken] as $run) {
        expect($run->refresh()->path)->not->toBeNull();
        Storage::disk('local')->assertExists($run->path.'/manifest.json');
    }
    expect(app(MaintenanceWindow::class)->active())->toBeFalse();
});

it('refuses backup administration while another backup is pending', function (string $command): void {
    $run = BackupRun::factory()->create();
    config(['vaultigen.backup_retention_enabled' => true]);

    $this->artisan($command)->assertFailed();

    expect($run->refresh()->state)->toBe(BackupRun::STATE_PENDING);
    expect(app(MaintenanceWindow::class)->active())->toBeFalse();
})->with(['vaultigen:backups:prune', 'vaultigen:backup-key:rotate']);

it('refuses interrupted-backup recovery without the offline confirmation', function (): void {
    $run = BackupRun::factory()->create(['state' => BackupRun::STATE_RUNNING]);

    $this->artisan('vaultigen:backup:recover')->assertFailed();

    expect($run->refresh()->state)->toBe(BackupRun::STATE_RUNNING);
});

it('reports a failed verification without modifying the target', function (): void {
    $this->artisan('vaultigen:backup:verify', ['directory' => Storage::disk('local')->path('absent')])
        ->expectsOutputToContain('Backup verification failed')->assertFailed();

    $this->assertDatabaseCount('backup_runs', 0);
});

it('warns about recovery-secret validation when verifying a legacy backup', function (): void {
    [$directory] = vaultigen_fake_backup_set(['database_driver' => DB::connection()->getDriverName()]);

    $this->artisan('vaultigen:backup:verify', ['directory' => $directory])
        ->expectsOutputToContain('Legacy set:')->assertSuccessful();

    $this->assertDatabaseCount('backup_runs', 0);
});
