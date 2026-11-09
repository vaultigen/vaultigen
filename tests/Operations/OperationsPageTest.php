<?php

use App\Filament\Pages\Operations;
use App\Models\BackupRun;
use App\Models\ImportRun;
use App\Models\KeyRotationRun;
use App\Models\User;
use App\Services\Operations\MaintenanceWindow;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

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

function operations_admin(): User
{
    return User::factory()->superAdmin()->withTwoFactor()->create();
}

function operation_code(User $user): string
{
    return AppAuthentication::make()->getCurrentCode($user, $user->app_authentication_secret);
}

it('is only accessible to installation administrators', function (): void {
    expect(Operations::canAccess())->toBeFalse();

    $this->actingAs(operations_admin());
    expect(Operations::canAccess())->toBeTrue();
});

it('renders readiness and recovery inputs', function (): void {
    $this->actingAs(operations_admin());

    Livewire::test(Operations::class)
        ->assertOk()
        ->assertSee('Backup readiness')
        ->assertSee('Recovery inputs');
});

it('starts a backup from the page', function (): void {
    $admin = operations_admin();
    $this->actingAs($admin);

    Livewire::test(Operations::class)
        ->callAction('runBackup')
        ->assertNotified(__('operations.notifications.backup_started'));

    expect(BackupRun::query()->where('state', BackupRun::STATE_COMPLETED)->count())->toBe(1);
});

it('reports a failed backup start to the administrator', function (): void {
    $admin = operations_admin();
    $this->actingAs($admin);
    ImportRun::factory()->queued()->create(['requested_by_user_id' => $admin->id]);

    Livewire::test(Operations::class)
        ->callAction('runBackup')
        ->assertNotified(__('operations.notifications.backup_not_started'));

    expect(BackupRun::query()->count())->toBe(0);
});

it('starts an installation key rewrap from the page', function (): void {
    $admin = operations_admin();
    $this->actingAs($admin);
    vaultigen_entry_fixture();
    config(['vaultigen.kek_keyring' => json_encode([
        'active_key_id' => 'kek-test-v2',
        'keys' => [
            'kek-test-v1' => base64_encode(str_repeat('k', 32)),
            'kek-test-v2' => base64_encode(str_repeat('j', 32)),
        ],
    ], JSON_THROW_ON_ERROR)]);

    Livewire::test(Operations::class)
        ->callAction('rotateKek', ['password' => 'password', 'code' => operation_code($admin)])
        ->assertNotified(__('operations.notifications.rotation_started'));

    expect(KeyRotationRun::query()->where('status', KeyRotationRun::STATUS_COMPLETED)->count())->toBe(1);
});

it('reports a failed rotation start to the administrator', function (): void {
    $admin = operations_admin();
    $this->actingAs($admin);

    Livewire::test(Operations::class)
        ->callAction('rotateKek', ['password' => 'password', 'code' => operation_code($admin)])
        ->assertNotified(__('operations.notifications.rotation_not_started'));

    expect(KeyRotationRun::query()->count())->toBe(0);
});

it('starts a vault key rotation from the page', function (): void {
    $admin = operations_admin();
    $this->actingAs($admin);
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    vaultigen_make_entry($alice, $vault);
    BackupRun::factory()->completed()->create(['requested_by_user_id' => $admin->id]);

    Livewire::test(Operations::class)
        ->callAction('rotateVaultKey', ['vault_id' => $vault->id, 'password' => 'password', 'code' => operation_code($admin)])
        ->assertNotified(__('operations.notifications.rotation_started'));

    expect(KeyRotationRun::query()->where('status', KeyRotationRun::STATUS_COMPLETED)->count())->toBe(1);
});

it('shows the active operation and its progress', function (): void {
    $admin = operations_admin();
    $this->actingAs($admin);
    BackupRun::factory()->create(['state' => BackupRun::STATE_RUNNING, 'requested_by_user_id' => $admin->id]);

    Livewire::test(Operations::class)->assertOk()->assertSee('A backup is running');

    DB::table('backup_runs')->delete();
    KeyRotationRun::factory()->create([
        'status' => KeyRotationRun::STATUS_RUNNING,
        'started_by_user_id' => $admin->id,
    ]);

    Livewire::test(Operations::class)
        ->assertOk()
        ->assertSee('A key rotation is running');
});

it('shows a localized error for a failed rotation', function (): void {
    $admin = operations_admin();
    $this->actingAs($admin);
    KeyRotationRun::factory()->create([
        'status' => KeyRotationRun::STATUS_FAILED,
        'error_code' => 'backup_required',
        'started_by_user_id' => $admin->id,
    ]);

    Livewire::test(Operations::class)
        ->assertOk()
        ->assertSee('Take a verified backup before retiring an old vault key version.');
});

it('disables the start actions while a maintenance window is open', function (): void {
    $admin = operations_admin();
    $this->actingAs($admin);
    app(MaintenanceWindow::class)->begin('backup', null, 'test');

    Livewire::test(Operations::class)
        ->assertOk()
        ->assertSee('Maintenance window open')
        ->assertActionDisabled('runBackup')
        ->assertActionDisabled('rotateKek')
        ->assertActionDisabled('rotateVaultKey');
});

it('disables the backup action and points at readiness when no backup key exists', function (): void {
    config(['vaultigen.backup_keyring' => null, 'vaultigen.backup_key_file' => '/nonexistent/backup.json']);
    $admin = operations_admin();
    $this->actingAs($admin);

    Livewire::test(Operations::class)
        ->assertOk()
        ->assertActionDisabled('runBackup')
        ->assertSee('No backup keyring is configured. Run vaultigen:backup-key:generate on the host.');
});
