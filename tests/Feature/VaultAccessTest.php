<?php

use App\Models\Team;
use App\Models\User;
use App\Services\TeamAdministration;
use App\Services\VaultAccess;
use App\Services\VaultMembership;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

test('platform roles alone grant no vault data access', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager);
    $access = app(VaultAccess::class);

    expect($access->permission($admin, $vault->id))->toBeNull()
        ->and($access->can($admin, $vault->id, 'viewer'))->toBeFalse()
        ->and($access->authority($admin, $vault->id))->toBeNull()
        ->and($access->explain($admin, $vault->id))->toBe([])
        ->and($access->visibleVaults($admin)->count())->toBe(0)
        ->and($access->visibleVaults($manager)->pluck('id')->all())->toBe([$vault->id])
        ->and($access->managesAnyVault($manager))->toBeTrue()
        ->and($access->managesAnyVault($admin))->toBeFalse();
});

test('direct grants are additive and the maximum level wins', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    $access = app(VaultAccess::class);
    $membership = app(VaultMembership::class);

    $membership->grant($alice, $vault->id, $bob->principal_id, 'viewer');
    expect($access->permission($bob, $vault->id))->toBe('viewer')
        ->and($access->can($bob, $vault->id, 'viewer'))->toBeTrue()
        ->and($access->can($bob, $vault->id, 'editor'))->toBeFalse()
        ->and($access->can($bob, $vault->id, 'manager'))->toBeFalse();

    $membership->grant($alice, $vault->id, $bob->principal_id, 'manager');
    expect($access->permission($bob, $vault->id))->toBe('manager')
        ->and($access->can($bob, $vault->id, 'editor'))->toBeTrue()
        ->and($access->managesAnyVault($bob))->toBeTrue();
});

test('team grants combine with direct grants at the maximum active permission', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    $team = Team::factory()->create(['name' => 'Operations']);
    app(TeamAdministration::class)->membership($admin, $team->id, $bob->id, true);

    $membership = app(VaultMembership::class);
    $membership->grant($alice, $vault->id, $bob->principal_id, 'viewer');
    $membership->grant($alice, $vault->id, $team->principal_id, 'editor');
    $access = app(VaultAccess::class);

    expect($access->permission($bob, $vault->id))->toBe('editor')
        ->and($access->permission($alice, $vault->id))->toBe('manager');

    $explanation = collect($access->explain($bob, $vault->id))->keyBy('kind');
    expect($explanation['team']['label'])->toBe('Operations')
        ->and($explanation['team']['permission'])->toBe('editor')
        ->and($explanation['user']['label'])->toBe("{$bob->name} ({$bob->email})")
        ->and($explanation['user']['permission'])->toBe('viewer')
        ->and($explanation['user']['expires_at'])->toBeNull();
});

test('expired and revoked grants stop access immediately and disappear from lists', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    $access = app(VaultAccess::class);

    app(VaultMembership::class)->grant($alice, $vault->id, $bob->principal_id, 'viewer', now()->addMinute()->toDateTimeString());
    expect($access->permission($bob, $vault->id))->toBe('viewer')
        ->and($access->authority($bob, $vault->id)['expires_at'])->not->toBeNull();

    $this->travel(2)->minutes();
    expect($access->permission($bob, $vault->id))->toBeNull()
        ->and($access->visibleVaults($bob)->count())->toBe(0)
        ->and($access->explain($bob, $vault->id))->toBe([]);

    $this->travelBack();
    $grantId = DB::table('vault_grants')->where('vault_id', $vault->id)->where('principal_id', $bob->principal_id)->whereNotNull('active_slot')->value('id');
    app(VaultMembership::class)->revoke($alice, $vault->id, $grantId);
    expect($access->permission($bob, $vault->id))->toBeNull()
        ->and($access->visibleVaults($bob)->count())->toBe(0);
});

test('authority expiry aggregates the winning grants and stays unlimited when one does', function () {
    $this->freezeTime();
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    $team = Team::factory()->create(['name' => 'Operations']);
    app(TeamAdministration::class)->membership($admin, $team->id, $bob->id, true);

    app(VaultMembership::class)->grant($alice, $vault->id, $bob->principal_id, 'editor', now()->addMinute()->toDateTimeString());
    $authority = app(VaultAccess::class)->authority($bob, $vault->id);
    expect($authority['permission'])->toBe('editor')
        ->and($authority['expires_at'])->not->toBeNull();

    app(VaultMembership::class)->grant($alice, $vault->id, $team->principal_id, 'editor', now()->addMinutes(5)->toDateTimeString());
    $authority = app(VaultAccess::class)->authority($bob, $vault->id);
    expect($authority['expires_at']->timestamp)->toBe(now()->addMinutes(5)->getTimestamp());

    app(VaultMembership::class)->grant($alice, $vault->id, $team->principal_id, 'editor');
    $authority = app(VaultAccess::class)->authority($bob, $vault->id);
    expect($authority['permission'])->toBe('editor')
        ->and($authority['expires_at'])->toBeNull();
});

test('batch authorities resolve many vaults at once and fail closed for empty or inactive input', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $first = vaultigen_make_vault($admin, $alice, ['name' => 'First']);
    $second = vaultigen_make_vault($admin, $alice, ['name' => 'Second']);
    $access = app(VaultAccess::class);

    $authorities = $access->authorities($alice, [$first->id, $second->id]);
    expect($authorities[$first->id]['permission'])->toBe('manager')
        ->and($authorities[$second->id]['permission'])->toBe('manager')
        ->and($authorities[$first->id]['expires_at'])->toBeNull();

    expect($access->authorities($alice, []))->toBe([]);

    DB::table('users')->where('id', $alice->id)->update(['is_active' => false]);
    $alice->refresh();
    expect($access->authorities($alice, [$first->id]))->toBe([]);
});

test('inactive users and unknown permission names fail closed', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    app(VaultMembership::class)->grant($alice, $vault->id, $bob->principal_id, 'viewer');
    $access = app(VaultAccess::class);

    expect($access->can($bob, $vault->id, 'viewer'))->toBeTrue();

    DB::table('users')->where('id', $bob->id)->update(['is_active' => false]);
    $bob->refresh();
    expect($access->permission($bob, $vault->id))->toBeNull()
        ->and($access->visibleVaults($bob)->count())->toBe(0)
        ->and($access->managesAnyVault($bob))->toBeFalse();

    expect(fn () => $access->can($admin, $vault->id, 'owner'))->toThrow(InvalidArgumentException::class, 'Unknown vault permission');
});

test('named data abilities map to permission levels and reject unknown names', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    app(VaultMembership::class)->grant($alice, $vault->id, $bob->principal_id, 'viewer');
    $access = app(VaultAccess::class);

    expect($access->allows($bob, $vault->id, 'metadata.view'))->toBeTrue()
        ->and($access->allows($bob, $vault->id, 'secret.reveal'))->toBeTrue()
        ->and($access->allows($bob, $vault->id, 'entry.create'))->toBeFalse()
        ->and($access->allows($alice, $vault->id, 'entry.history'))->toBeTrue();

    expect(fn () => $access->allows($alice, $vault->id, 'vault.teleport'))
        ->toThrow(InvalidArgumentException::class);
});
