<?php

use App\Models\AuditEvent;
use App\Models\BackupRun;
use App\Services\Operations\InstallationState;
use App\Services\Operations\MaintenanceWindow;
use App\Services\Operations\OperationLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Fixtures\FakeBackupRuntime;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('attachments');
    config([
        'vaultigen.backup_root' => Storage::disk('local')->path('backups'),
        'vaultigen.backup_keyring' => json_encode([
            'active_key_id' => 'backup-test-v1',
            'keys' => ['backup-test-v1' => base64_encode(str_repeat('b', 32))],
        ], JSON_THROW_ON_ERROR),
    ]);
    DB::table('installation_settings')->where('id', 1)->update(['bootstrapped_at' => now(), 'installation_id' => config('vaultigen.installation_id')]);
});

afterEach(function (): void {
    if (! Schema::hasTable('vault_images')) {
        $this->artisan('migrate', ['--force' => true]);
    }
    DB::table('installation_settings')->where('id', 1)->update(['bootstrapped_at' => null, 'installation_id' => null]);
});

it('exits without a backup or audit event when no migrations are pending', function (): void {
    $this->artisan('vaultigen:migrate')
        ->expectsOutputToContain('No pending migrations.')
        ->assertSuccessful();

    expect(BackupRun::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('backs up the previous schema before applying pending migrations once', function (): void {
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true]);

    $this->artisan('vaultigen:migrate')
        ->expectsOutputToContain('Taking a verified backup first.')
        ->assertSuccessful();

    $run = BackupRun::query()->sole();
    expect($run->state)->toBe(BackupRun::STATE_COMPLETED)
        ->and($run->trigger)->toBe(BackupRun::TRIGGER_MIGRATION);
    Storage::disk('local')->assertExists('backups/'.$run->id.'/manifest.json');
    expect(app(InstallationState::class)->pendingMigrations())->toBe([]);

    $this->artisan('vaultigen:migrate')->expectsOutputToContain('No pending migrations.')->assertSuccessful();
    expect(BackupRun::query()->count())->toBe(1);
});

it('applies no migration when the pre-migration backup :case', function (Closure $breakBackup, int $failedRuns): void {
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true]);
    $breakBackup();

    $this->artisan('vaultigen:migrate')
        ->expectsOutputToContain('No migration was applied.')
        ->assertFailed();

    expect(BackupRun::query()->where('state', BackupRun::STATE_FAILED)->count())->toBe($failedRuns)
        ->and(BackupRun::query()->where('state', BackupRun::STATE_COMPLETED)->exists())->toBeFalse()
        ->and(Schema::hasColumn('vaults', 'purge_previous_state'))->toBeFalse();
})->with([
    'fails while writing the set' => [fn () => FakeBackupRuntime::refuseStorage('makeDirectory', fn (): bool => true), 1],
    'cannot start during another maintenance window' => [fn () => app(MaintenanceWindow::class)->begin('rotation', null, 'test'), 0],
]);

it('refuses to migrate while a restore is incomplete', function (): void {
    file_put_contents(OperationLock::restoreMarker(), '{}');

    try {
        $this->artisan('vaultigen:migrate')
            ->expectsOutputToContain('A restore is incomplete.')
            ->assertFailed();
    } finally {
        unlink(OperationLock::restoreMarker());
    }

    expect(BackupRun::query()->count())->toBe(0);
});

it('refuses an installation that setup has not initialized', function (): void {
    DB::table('installation_settings')->where('id', 1)->update(['bootstrapped_at' => null]);

    $this->artisan('vaultigen:migrate')
        ->expectsOutputToContain('docker compose run --rm setup')
        ->assertFailed();
});

it('refuses to start without the original keys', function (array $settings, string $missing): void {
    config($settings);

    $this->artisan('vaultigen:migrate')
        ->expectsOutputToContain('Installation keys are missing: '.$missing.'.')
        ->assertFailed();
})->with([
    'application key' => [['app.key' => null], 'app_key'],
    'installation KEK keyring' => [['vaultigen.kek_provider' => 'file', 'vaultigen.kek_file' => '/nonexistent/kek.json'], 'kek'],
]);

it('refuses keys that belong to another installation', function (): void {
    DB::table('installation_settings')->where('id', 1)->update(['installation_id' => '11111111-1111-4111-8111-111111111111']);

    $this->artisan('vaultigen:migrate')
        ->expectsOutputToContain('do not belong to this database')
        ->assertFailed();
});

it('gives up when another operation keeps the operations lock', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $competitor = fopen(storage_path('framework/backup-operations.lock'), 'c');
    flock($competitor, LOCK_EX);

    try {
        $this->artisan('vaultigen:migrate', ['--wait' => 2])
            ->expectsOutputToContain('Another operation holds the operations lock.')
            ->assertFailed();
    } finally {
        flock($competitor, LOCK_UN);
        fclose($competitor);
    }

    Sleep::assertSleptTimes(2);
});
