<?php

use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 */
function vaultigen_insert_vault(array $overrides = []): void
{
    $custodian = User::factory()->withTwoFactor()->create();

    DB::table('vaults')->insert([
        'id' => (string) Str::uuid(),
        'name' => 'Direct vault',
        'normalized_name' => 'direct-vault',
        'slug' => 'direct-vault-'.Str::lower(Str::random(6)),
        'kind' => 'shared',
        'primary_custodian_user_id' => $custodian->id,
        'state' => 'active',
        'created_by_user_id' => $custodian->id,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function vaultigen_grant_attributes(Vault $vault, User $principalOwner, array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid(),
        'principal_id' => $principalOwner->principal_id,
        'vault_id' => $vault->id,
        'permission' => 'viewer',
        'source' => 'manual',
        'granted_by_user_id' => $principalOwner->id,
        'active_slot' => 1,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

test('vault state constraints reject invalid direct writes', function (array $overrides) {
    expect(fn () => vaultigen_insert_vault($overrides))->toThrow(QueryException::class);
})->with([
    [['kind' => 'bogus']],
    [['state' => 'bogus']],
    [['kind' => 'private_workspace']],
]);

test('vault state constraints reject a personal workspace flag on a shared vault', function () {
    $owner = User::factory()->withTwoFactor()->create();
    expect(fn () => vaultigen_insert_vault(['private_workspace_owner_user_id' => $owner->id]))
        ->toThrow(QueryException::class);
});

test('grant constraints reject invalid permissions sources slots and lifecycles', function (array $overrides) {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager);

    expect(fn () => DB::table('vault_grants')->insert(vaultigen_grant_attributes($vault, $bob, $overrides)))
        ->toThrow(QueryException::class);
})->with([
    [['permission' => 'owner']],
    [['source' => 'syspass']],
    [['active_slot' => 2]],
    [['revoked_at' => now()]],
    [['active_slot' => null]],
]);

test('grants require a real principal and at most one active row per principal and vault', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager);

    expect(fn () => DB::transaction(fn () => DB::table('vault_grants')->insert(vaultigen_grant_attributes($vault, $bob, ['principal_id' => (string) Str::uuid()]))))
        ->toThrow(QueryException::class);

    DB::table('vault_grants')->insert(vaultigen_grant_attributes($vault, $bob));
    expect(fn () => DB::transaction(fn () => DB::table('vault_grants')->insert(vaultigen_grant_attributes($vault, $bob))))
        ->toThrow(QueryException::class);

    DB::table('vault_grants')->insert([
        vaultigen_grant_attributes($vault, $bob, ['active_slot' => null, 'revoked_at' => now(), 'permission' => 'viewer']),
        vaultigen_grant_attributes($vault, $bob, ['active_slot' => null, 'revoked_at' => now(), 'permission' => 'editor']),
    ]);
    expect(DB::table('vault_grants')->where('vault_id', $vault->id)->where('principal_id', $bob->principal_id)->count())->toBe(3);
});

test('vault key wrappings reject unknown recipient types', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager);
    $wrapping = DB::table('vault_key_wrappings')->sole();

    expect(fn () => DB::table('vault_key_wrappings')->where('id', $wrapping->id)->update(['recipient_type' => 'user_device']))
        ->toThrow(QueryException::class);
});

test('active keys must belong to the same vault as their key version', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $vaultA = vaultigen_make_vault($admin, $manager, ['name' => 'Vault A']);
    $vaultB = vaultigen_make_vault($admin, $manager, ['name' => 'Vault B']);
    $versionA = DB::table('vault_key_versions')->where('vault_id', $vaultA->id)->sole();

    DB::table('vault_active_keys')->where('vault_id', $vaultB->id)->delete();
    expect(fn () => DB::table('vault_active_keys')->insert([
        'vault_id' => $vaultB->id,
        'vault_key_version_id' => $versionA->id,
    ]))->toThrow(QueryException::class);
});
