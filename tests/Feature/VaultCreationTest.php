<?php

use App\Models\User;
use App\Models\Vault;
use App\Services\VaultAccess;
use App\Services\VaultAdministration;
use App\Services\VaultCreationPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

function vaultigen_set_creation_policy(string $policy, int $perUser = 50, int $installation = 500): void
{
    DB::table('installation_settings')->where('id', 1)->update([
        'vault_creation_policy' => $policy,
        'vault_creation_limit_per_user' => $perUser,
        'vault_creation_limit_installation' => $installation,
    ]);
}

test('vault creators create their own vault and become its manager and custodian', function () {
    $creator = User::factory()->vaultCreator()->withTwoFactor()->create();

    $id = app(VaultAdministration::class)->createOwn($creator, [
        'name' => 'Acme – Website',
        'description' => 'Customer project credentials.',
        'icon' => 'globe-alt',
    ]);

    $vault = Vault::query()->findOrFail($id);
    expect($vault->created_by_user_id)->toBe($creator->id)
        ->and($vault->primary_custodian_user_id)->toBe($creator->id)
        ->and($vault->kind)->toBe(Vault::KIND_SHARED)
        ->and(app(VaultAccess::class)->permission($creator, $vault->id))->toBe('manager');
    $this->assertDatabaseHas('vault_grants', [
        'vault_id' => $vault->id,
        'principal_id' => $creator->principal_id,
        'permission' => 'manager',
        'active_slot' => 1,
    ]);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $creator->id, 'action' => 'vault.created']);
});

test('the installation policy decides who may create vaults', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $creator = User::factory()->vaultCreator()->withTwoFactor()->create();
    $ordinary = User::factory()->withTwoFactor()->create();
    $inactive = User::factory()->vaultCreator()->withTwoFactor()->inactive()->create();
    $policy = app(VaultCreationPolicy::class);

    vaultigen_set_creation_policy('vault_creators');
    expect($policy->policy())->toBe('vault_creators')
        ->and($policy->allows($admin))->toBeTrue()
        ->and($policy->allows($creator))->toBeTrue()
        ->and($policy->allows($ordinary))->toBeFalse()
        ->and($policy->allows($inactive))->toBeFalse()
        ->and(Gate::forUser($creator)->allows('create-vault'))->toBeTrue()
        ->and(Gate::forUser($ordinary)->allows('create-vault'))->toBeFalse();

    vaultigen_set_creation_policy('admins');
    expect($policy->policy())->toBe('admins')
        ->and($policy->allows($admin))->toBeTrue()
        ->and($policy->allows($creator))->toBeFalse()
        ->and($policy->allows($ordinary))->toBeFalse();

    vaultigen_set_creation_policy('any_active_user');
    expect($policy->allows($ordinary))->toBeTrue();

    vaultigen_set_creation_policy('not-a-policy');
    expect($policy->policy())->toBe('vault_creators')
        ->and($policy->allows($creator))->toBeTrue();
});

test('ordinary users cannot create their own vault', function () {
    $ordinary = User::factory()->withTwoFactor()->create();

    expect(fn () => app(VaultAdministration::class)->createOwn($ordinary, ['name' => 'Nope']))
        ->toThrow(AuthorizationException::class);
    expect(Vault::query()->count())->toBe(0);
});

test('own vault creation validates input without requiring a custodian or manager', function () {
    $creator = User::factory()->vaultCreator()->withTwoFactor()->create();
    $service = app(VaultAdministration::class);

    $service->createOwn($creator, ['name' => 'Bare project', 'description' => null, 'icon' => null]);
    expect(Vault::query()->sole()->description)->toBeNull();

    foreach ([
        [['name' => ''], 'name'],
        [['name' => str_repeat('x', 101)], 'name'],
        [['name' => 'Valid', 'description' => str_repeat('x', 2001)], 'description'],
        [['name' => 'Valid', 'icon' => 'not-a-known-icon'], 'icon'],
    ] as [$data, $field]) {
        try {
            $service->createOwn($creator, $data);
            $this->fail("Expected a validation error for {$field}.");
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field);
        }
    }

    expect(Vault::query()->count())->toBe(1);
});

test('per-creator and installation quotas deny and audit further creation', function () {
    $creator = User::factory()->vaultCreator()->withTwoFactor()->create();
    $service = app(VaultAdministration::class);

    vaultigen_set_creation_policy('vault_creators', perUser: 1);
    $service->createOwn($creator, ['name' => 'First']);

    try {
        $service->createOwn($creator, ['name' => 'Second']);
        $this->fail('Expected the per-creator quota to deny the second vault.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('name');
    }
    expect(Vault::query()->count())->toBe(1)
        ->and(app(VaultCreationPolicy::class)->perUserLimit())->toBe(1);

    vaultigen_set_creation_policy('vault_creators', perUser: 50, installation: 1);
    try {
        $service->createOwn($creator, ['name' => 'Third']);
        $this->fail('Expected the installation quota to deny the vault.');
    } catch (ValidationException) {
        // expected
    }
    expect(app(VaultCreationPolicy::class)->installationLimit())->toBe(1)
        ->and(Vault::query()->count())->toBe(1);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $creator->id, 'action' => 'vault.creation_denied', 'outcome' => 'denied']);
});

test('own vault creation rolls back when the audit write fails', function () {
    $creator = User::factory()->vaultCreator()->withTwoFactor()->create();
    DB::table('audit_chain_heads')->where('id', 1)->delete();

    expect(fn () => app(VaultAdministration::class)->createOwn($creator, ['name' => 'Doomed']))
        ->toThrow(RuntimeException::class, 'Audit storage is unavailable');

    expect(Vault::query()->count())->toBe(0)
        ->and(DB::table('vault_grants')->count())->toBe(0);
});
