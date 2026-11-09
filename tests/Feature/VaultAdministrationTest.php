<?php

use App\Models\User;
use App\Models\Vault;
use App\Services\VaultAccess;
use App\Services\VaultAdministration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('admins create a complete vault shell without gaining implicit access', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();

    $vault = vaultigen_make_vault($admin, $manager, [
        'name' => 'Operations',
        'description' => 'Shared operational credentials.',
        'icon' => 'server-stack',
        'primary_custodian_user_id' => $custodian->id,
    ]);

    expect($vault->name)->toBe('Operations')
        ->and($vault->slug)->toBe('operations')
        ->and($vault->normalized_name)->toBe('operations')
        ->and($vault->kind)->toBe('shared')
        ->and($vault->state)->toBe('active')
        ->and($vault->primary_custodian_user_id)->toBe($custodian->id)
        ->and($vault->created_by_user_id)->toBe($admin->id);

    $this->assertDatabaseHas('vault_key_versions', ['vault_id' => $vault->id, 'version' => 1, 'created_by_user_id' => $admin->id]);
    $this->assertDatabaseHas('vault_active_keys', ['vault_id' => $vault->id]);
    $this->assertDatabaseHas('vault_grants', [
        'vault_id' => $vault->id,
        'principal_id' => $manager->principal_id,
        'permission' => 'manager',
        'source' => 'manual',
        'granted_by_user_id' => $admin->id,
        'active_slot' => 1,
    ]);
    $this->assertDatabaseMissing('vault_grants', ['vault_id' => $vault->id, 'principal_id' => $admin->principal_id]);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'action' => 'vault.created']);
    expect(app(VaultAccess::class)->permission($admin, $vault->id))->toBeNull();
});

test('vault creation validates input', function (array $changes, string $field) {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $data = [
        'name' => 'Operations',
        'description' => null,
        'icon' => null,
        'primary_custodian_user_id' => $manager->id,
        'initial_manager_user_id' => $manager->id,
        ...$changes,
    ];

    try {
        app(VaultAdministration::class)->create($admin, $data);
        $this->fail("Expected validation error for {$field}.");
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect(DB::table('vaults')->count())->toBe(0);
})->with([
    [['name' => ''], 'name'],
    [['name' => str_repeat('x', 101)], 'name'],
    [['description' => str_repeat('x', 2001)], 'description'],
    [['icon' => 'not-a-known-icon'], 'icon'],
    [['primary_custodian_user_id' => 'not-a-uuid'], 'primary_custodian_user_id'],
    [['primary_custodian_user_id' => '00000000-0000-0000-0000-000000000000'], 'primary_custodian_user_id'],
    [['initial_manager_user_id' => '00000000-0000-0000-0000-000000000000'], 'initial_manager_user_id'],
]);

test('vault creation accepts omitted optional attributes', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();

    $vault = vaultigen_make_vault($admin, $manager, ['name' => 'Bare shell']);

    expect($vault->description)->toBeNull()
        ->and($vault->icon)->toBeNull();
});

test('ordinary users cannot create or update vault shells', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $user = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager);
    $data = [
        'name' => 'Renamed',
        'description' => null,
        'icon' => null,
        'primary_custodian_user_id' => $manager->id,
        'initial_manager_user_id' => $manager->id,
    ];

    expect(fn () => app(VaultAdministration::class)->create($user, $data))->toThrow(AuthorizationException::class);
    expect(fn () => app(VaultAdministration::class)->update($user, $vault->id, $data))->toThrow(AuthorizationException::class);
    expect(DB::table('vaults')->count())->toBe(1);
});

test('vault shells can be updated and missing vaults are rejected', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager);
    $service = app(VaultAdministration::class);

    $service->update($admin, $vault->id, [
        'name' => 'Renamed vault',
        'description' => 'Updated description.',
        'icon' => 'lock-closed',
    ]);

    $vault->refresh();
    expect($vault->name)->toBe('Renamed vault')
        ->and($vault->slug)->toBe('operations')
        ->and($vault->description)->toBe('Updated description.')
        ->and($vault->icon)->toBe('lock-closed')
        ->and($vault->primary_custodian_user_id)->toBe($manager->id);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'action' => 'vault.updated']);

    $service->transferCustodian($admin, $vault->id, $custodian->id);
    expect($vault->fresh()->primary_custodian_user_id)->toBe($custodian->id);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'action' => 'vault.custodian_changed']);

    expect(fn () => $service->update($admin, '00000000-0000-0000-0000-000000000000', [
        'name' => 'Nowhere',
        'description' => null,
        'icon' => null,
    ]))->toThrow(NotFoundHttpException::class);
});

test('vault slugs stay unique and fall back for unnamed slugs', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();

    vaultigen_make_vault($admin, $manager, ['name' => 'Team Vault']);
    vaultigen_make_vault($admin, $manager, ['name' => 'Team Vault']);
    $third = vaultigen_make_vault($admin, $manager, ['name' => 'Team Vault']);
    $fallback = vaultigen_make_vault($admin, $manager, ['name' => '???']);

    expect($third->slug)->toBe('team-vault-3')
        ->and($fallback->slug)->toBe('vault')
        ->and(Vault::query()->orderBy('slug')->pluck('slug')->all())
        ->toBe(['team-vault', 'team-vault-2', 'team-vault-3', 'vault']);
});

test('vault creation rolls back completely when the audit write fails', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    DB::table('audit_chain_heads')->where('id', 1)->delete();

    expect(fn () => app(VaultAdministration::class)->create($admin, [
        'name' => 'Doomed',
        'description' => null,
        'icon' => null,
        'primary_custodian_user_id' => $manager->id,
        'initial_manager_user_id' => $manager->id,
    ]))->toThrow(RuntimeException::class, 'Audit storage is unavailable');

    expect(DB::table('vaults')->count())->toBe(0)
        ->and(DB::table('vault_key_versions')->count())->toBe(0)
        ->and(DB::table('vault_key_wrappings')->count())->toBe(0)
        ->and(DB::table('vault_active_keys')->count())->toBe(0)
        ->and(DB::table('vault_grants')->count())->toBe(0);
});
