<?php

use App\Models\User;
use App\Services\VaultAccess;
use App\Services\VaultAdministration;
use App\Services\VaultMembership;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('vault access exposes custodianship and governed vaults', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $outsider = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $admin, [
        'name' => 'Governed',
        'primary_custodian_user_id' => $custodian->id,
    ]);
    $access = app(VaultAccess::class);

    expect($access->isCustodian($custodian, $vault->id))->toBeTrue()
        ->and($access->canGovern($custodian, $vault->id))->toBeTrue()
        ->and($access->canGovern($admin, $vault->id))->toBeTrue()
        ->and($access->isCustodian($outsider, $vault->id))->toBeFalse()
        ->and($access->canGovern($outsider, $vault->id))->toBeFalse()
        ->and($access->governsAnyVault($custodian))->toBeTrue()
        ->and($access->governsAnyVault($outsider))->toBeFalse()
        ->and($access->governedVaults($admin)->pluck('id')->all())->toBe([$vault->id])
        ->and($access->governedVaults($custodian)->pluck('id')->all())->toBe([$vault->id])
        ->and($access->governedVaults($outsider)->count())->toBe(0)
        ->and($access->administeredVaults($admin)->pluck('id')->all())->toBe([$vault->id])
        ->and($access->administeredVaults($custodian)->pluck('id')->all())->toBe([$vault->id])
        ->and($access->administeredVaults($outsider)->count())->toBe(0);

    DB::table('users')->where('id', $custodian->id)->update(['is_active' => false]);
    $custodian->refresh();
    expect($access->isCustodian($custodian, $vault->id))->toBeFalse()
        ->and($access->governedVaults($custodian)->count())->toBe(0)
        ->and($access->administeredVaults($custodian)->count())->toBe(0)
        ->and($custodian->isVaultCreator())->toBeFalse();
});

test('custodians managers and admins can edit vault metadata but outsiders cannot', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $outsider = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager, ['primary_custodian_user_id' => $custodian->id]);
    $service = app(VaultAdministration::class);

    $service->update($custodian, $vault->id, ['name' => 'By custodian']);
    expect($vault->fresh()->name)->toBe('By custodian');

    $service->update($manager, $vault->id, ['name' => 'By manager']);
    expect($vault->fresh()->name)->toBe('By manager');

    expect(fn () => $service->update($outsider, $vault->id, ['name' => 'By outsider']))
        ->toThrow(AuthorizationException::class);
});

test('only a custodian or administrator can transfer custodianship', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $newCustodian = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager, ['primary_custodian_user_id' => $custodian->id]);
    $service = app(VaultAdministration::class);

    expect(fn () => $service->transferCustodian($manager, $vault->id, $newCustodian->id))
        ->toThrow(AuthorizationException::class);

    $service->transferCustodian($custodian, $vault->id, $newCustodian->id);
    expect($vault->fresh()->primary_custodian_user_id)->toBe($newCustodian->id);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $custodian->id, 'action' => 'vault.custodian_changed']);

    expect(fn () => $service->transferCustodian($admin, $vault->id, '00000000-0000-0000-0000-000000000000'))
        ->toThrow(ValidationException::class);
});

test('vault state transitions are governed and audited', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $outsider = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager, ['primary_custodian_user_id' => $custodian->id]);
    $service = app(VaultAdministration::class);

    $service->setState($custodian, $vault->id, 'read_only');
    expect($vault->fresh()->state)->toBe('read_only');
    $this->assertDatabaseHas('audit_events', ['action' => 'vault.updated']);

    $service->setState($admin, $vault->id, 'archived');
    expect($vault->fresh()->state)->toBe('archived');
    $this->assertDatabaseHas('audit_events', ['action' => 'vault.archived']);

    $service->setState($admin, $vault->id, 'active');
    expect($vault->fresh()->state)->toBe('active');
    $this->assertDatabaseHas('audit_events', ['action' => 'vault.reactivated']);

    expect(fn () => $service->setState($custodian, $vault->id, 'not-a-state'))->toThrow(ValidationException::class);
    expect(fn () => $service->setState($custodian, $vault->id, 'active'))->toThrow(ValidationException::class);
    expect(fn () => $service->setState($outsider, $vault->id, 'archived'))->toThrow(AuthorizationException::class);
});

test('archived vaults are frozen until reactivated', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager, ['primary_custodian_user_id' => $custodian->id]);
    $service = app(VaultAdministration::class);
    $service->setState($custodian, $vault->id, 'archived');

    expect(fn () => $service->update($custodian, $vault->id, ['name' => 'Frozen']))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->transferCustodian($custodian, $vault->id, $manager->id))
        ->toThrow(ValidationException::class);
    expect(fn () => app(VaultMembership::class)->grant($manager, $vault->id, $custodian->principal_id, 'viewer'))
        ->toThrow(ValidationException::class);
    $grantId = DB::table('vault_grants')->where('vault_id', $vault->id)->whereNotNull('active_slot')->value('id');
    expect(fn () => app(VaultMembership::class)->revoke($manager, $vault->id, (string) $grantId))
        ->toThrow(ValidationException::class);
});

test('purge initiation marks a vault pending purge once', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $outsider = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $admin, ['primary_custodian_user_id' => $custodian->id]);
    $service = app(VaultAdministration::class);

    $service->initiatePurge($custodian, $vault->id);
    expect($vault->fresh()->state)->toBe('pending_purge');
    $this->assertDatabaseHas('audit_events', ['action' => 'vault.purge_initiated']);

    expect(fn () => $service->initiatePurge($custodian, $vault->id))->toThrow(HttpException::class);
    expect(fn () => $service->setState($custodian, $vault->id, 'active'))->toThrow(HttpException::class);
    expect(fn () => $service->initiatePurge($outsider, $vault->id))->toThrow(AuthorizationException::class);
});

test('a stranded vault can be recovered by an administrator with strong re-authentication', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $newManager = User::factory()->withTwoFactor()->create();
    $outsider = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager);
    DB::table('vault_grants')->where('vault_id', $vault->id)->update(['active_slot' => null, 'revoked_at' => now()]);
    $service = app(VaultAdministration::class);

    $code = AppAuthentication::make()->getCurrentCode($admin, $admin->app_authentication_secret);
    $service->recover($admin, $vault->id, $newManager->id, 'Manager left the company', 'password', $code);

    expect(app(VaultAccess::class)->permission($newManager, $vault->id))->toBe('manager');
    $this->assertDatabaseHas('vault_grants', ['vault_id' => $vault->id, 'principal_id' => $newManager->principal_id, 'source' => 'manual', 'reason' => 'Manager left the company']);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'action' => 'vault.recovered']);

    expect(fn () => $service->recover($outsider, $vault->id, $newManager->id, 'Nope', 'password', $code))
        ->toThrow(AuthorizationException::class);
});

test('recovery is refused while a vault still has an active manager and validates its reason', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager);
    $code = AppAuthentication::make()->getCurrentCode($admin, $admin->app_authentication_secret);
    $service = app(VaultAdministration::class);

    expect(fn () => $service->recover($admin, $vault->id, $manager->id, 'Still managed', 'password', $code))
        ->toThrow(ValidationException::class);

    DB::table('vault_grants')->where('vault_id', $vault->id)->update(['active_slot' => null, 'revoked_at' => now()]);
    expect(fn () => $service->recover($admin, $vault->id, $manager->id, '   ', 'password', $code))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->recover($admin, $vault->id, '00000000-0000-0000-0000-000000000000', 'Reason', 'password', $code))
        ->toThrow(ValidationException::class);
});
