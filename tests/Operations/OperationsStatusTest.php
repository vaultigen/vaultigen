<?php

use App\Contracts\KekProvider;
use App\Models\BackupRun;
use App\Models\KeyRotationRun;
use App\Models\User;
use App\Services\Operations\BackupKeyring;
use App\Services\Operations\DatabaseSnapshot;
use App\Services\Operations\MaintenanceWindow;
use App\Services\Operations\OperationsStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;

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

function operations_status(?ExecutableFinder $finder = null, ?DatabaseSnapshot $snapshot = null): OperationsStatus
{
    return new OperationsStatus(
        app(BackupKeyring::class),
        app(KekProvider::class),
        $snapshot ?? app(DatabaseSnapshot::class),
        app(MaintenanceWindow::class),
        $finder ?? new ExecutableFinder,
    );
}

it('reports readiness, recovery inputs, and an empty history', function (): void {
    $report = operations_status()->report();

    expect($report['readiness'])->toHaveCount(4)
        ->and($report['latest_backup'])->toBeNull()
        ->and($report['rotations'])->toBe([])
        ->and($report['maintenance'])->toBeNull()
        ->and($report['key_health']['healthy'])->toBeTrue();

    $labels = array_column($report['recovery_inputs'], 'label');
    expect($labels)->toContain((string) __('operations.recovery.installation_id'));
});

it('reports missing backup readiness', function (): void {
    config(['vaultigen.backup_keyring' => null, 'vaultigen.backup_key_file' => '/nonexistent/backup.json']);
    Storage::shouldReceive('build')->andThrow(new RuntimeException('read-only'));

    $report = operations_status()->report();

    expect($report['readiness'][0]['state'])->toBe('error')
        ->and($report['readiness'][1]['state'])->toBe('error');
});

it('reports the database tool state per driver', function (): void {
    foreach ([
        ['mysql', 'mysqldump', true],
        ['pgsql', 'pg_dump', true],
        ['mariadb', 'mariadb-dump', true],
        ['sqlsrv', null, false],
    ] as [$driver, $binary, $available]) {
        $snapshot = Mockery::mock(DatabaseSnapshot::class)->makePartial();
        $snapshot->shouldReceive('driver')->andReturn($driver);
        $finder = Mockery::mock(ExecutableFinder::class);
        if ($binary !== null) {
            $finder->shouldReceive('find')->andReturn($available ? '/usr/bin/'.$binary : null);
        }

        $report = operations_status($finder, $snapshot)->readiness();

        expect($report[2]['state'])->toBe($available ? 'healthy' : 'error');
    }
});

it('reports mariadb as available when only mysqldump exists', function (): void {
    $snapshot = Mockery::mock(DatabaseSnapshot::class)->makePartial();
    $snapshot->shouldReceive('driver')->andReturn('mariadb');
    $finder = Mockery::mock(ExecutableFinder::class);
    $finder->shouldReceive('find')->with('mariadb-dump')->andReturn(null);
    $finder->shouldReceive('find')->with('mysqldump')->andReturn('/usr/bin/mysqldump');

    expect(operations_status($finder, $snapshot)->readiness()[2]['state'])->toBe('healthy');
});

it('reports a busy maintenance window for each kind of operation', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    app(MaintenanceWindow::class)->begin('backup', null, 'test');
    expect(operations_status()->readiness()[3]['state'])->toBe('warning');
    app(MaintenanceWindow::class)->end();

    BackupRun::factory()->create(['state' => BackupRun::STATE_PENDING, 'requested_by_user_id' => $admin->id]);
    expect(operations_status()->readiness()[3]['state'])->toBe('warning');
    DB::table('backup_runs')->delete();

    KeyRotationRun::factory()->create(['status' => KeyRotationRun::STATUS_PENDING, 'started_by_user_id' => $admin->id]);
    expect(operations_status()->readiness()[3]['state'])->toBe('warning');
});

it('reports the latest backup and rotation history', function (): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    BackupRun::factory()->completed()->create(['requested_by_user_id' => $admin->id]);
    KeyRotationRun::factory()->create([
        'status' => KeyRotationRun::STATUS_FAILED,
        'error_code' => 'backup_required',
        'started_by_user_id' => $admin->id,
    ]);

    $report = operations_status()->report();

    expect($report['latest_backup']['state'])->toBe(BackupRun::STATE_COMPLETED)
        ->and($report['rotations'])->toHaveCount(1)
        ->and($report['rotations'][0]['error_code'])->toBe('backup_required');
});

it('flags a referenced key that is missing from the keyring', function (): void {
    ['admin' => $admin, 'vault' => $vault] = vaultigen_entry_fixture();
    $versionId = (string) DB::table('vault_active_keys')->where('vault_id', $vault->id)->value('vault_key_version_id');
    DB::table('vault_key_wrappings')->where('vault_key_version_id', $versionId)->update(['recipient_key_id' => 'missing-kek']);

    $health = operations_status()->keyHealth();

    expect($health['healthy'])->toBeFalse()
        ->and($health['missing_key_ids'])->toContain('missing-kek');
});

it('flags a vault without an active key', function (): void {
    ['vault' => $vault] = vaultigen_entry_fixture();
    DB::table('vault_active_keys')->where('vault_id', $vault->id)->delete();

    $health = operations_status()->keyHealth();

    expect($health['healthy'])->toBeFalse()
        ->and($health['vaults_without_key'])->toBe(1);
});

it('reports invalid backup settings', function (): void {
    config(['vaultigen.backup_retention_days' => 0]);

    expect(operations_status()->backupHealth())->toBe(['state' => 'error', 'code' => 'backup_configuration_invalid']);
});

it('reports an interrupted backup without releasing its maintenance window', function (): void {
    $this->freezeTime();
    $run = BackupRun::factory()->create(['state' => BackupRun::STATE_RUNNING, 'created_at' => now()->subHours(2)]);
    app(MaintenanceWindow::class)->begin('backup', (string) $run->id, 'test');

    expect(operations_status()->backupHealth())->toBe(['state' => 'error', 'code' => 'backup_stale']);
    expect($run->refresh()->state)->toBe(BackupRun::STATE_RUNNING);
    expect(app(MaintenanceWindow::class)->active())->toBeTrue();
});

it('reports the latest failed backup with its actionable error', function (): void {
    BackupRun::factory()->create(['state' => BackupRun::STATE_FAILED, 'error_code' => 'queue_failed']);

    expect(operations_status()->backupHealth())->toBe(['state' => 'error', 'code' => 'queue_failed']);
});
