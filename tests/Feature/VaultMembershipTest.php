<?php

use App\Models\User;
use App\Services\VaultMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('managers create replace and revoke grants while preserving history', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    $membership = app(VaultMembership::class);

    $membership->grant($alice, $vault->id, $bob->principal_id, 'viewer');
    $this->assertDatabaseHas('vault_grants', [
        'vault_id' => $vault->id,
        'principal_id' => $bob->principal_id,
        'permission' => 'viewer',
        'source' => 'manual',
        'active_slot' => 1,
        'revoked_at' => null,
    ]);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $alice->id, 'action' => 'grant.created']);

    $membership->grant($alice, $vault->id, $bob->principal_id, 'editor', now()->addDay()->toDateTimeString());
    $grants = DB::table('vault_grants')->where('vault_id', $vault->id)->where('principal_id', $bob->principal_id)->orderBy('created_at')->orderByRaw('active_slot is not null')->get();
    expect($grants)->toHaveCount(2)
        ->and($grants[0]->active_slot)->toBeNull()
        ->and($grants[0]->revocation_reason)->toBe('replaced')
        ->and($grants[0]->revoked_at)->not->toBeNull()
        ->and($grants[1]->active_slot)->toBe(1)
        ->and($grants[1]->permission)->toBe('editor');

    $membership->revoke($alice, $vault->id, (string) $grants[1]->id);
    $this->assertDatabaseHas('vault_grants', ['id' => $grants[1]->id, 'active_slot' => null, 'revoked_by_user_id' => $alice->id]);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $alice->id, 'action' => 'grant.revoked']);

    $this->expectException(NotFoundHttpException::class);
    $membership->revoke($alice, $vault->id, (string) Str::uuid());
});

test('expired active rows are closed before a replacement grant is written', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);

    $expiredGrantId = (string) Str::uuid();
    DB::table('vault_grants')->insert([
        'id' => $expiredGrantId,
        'principal_id' => $bob->principal_id,
        'vault_id' => $vault->id,
        'permission' => 'viewer',
        'source' => 'manual',
        'granted_by_user_id' => $alice->id,
        'expires_at' => now()->subDay(),
        'active_slot' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(VaultMembership::class)->grant($alice, $vault->id, $bob->principal_id, 'viewer');

    $grants = DB::table('vault_grants')->where('vault_id', $vault->id)->where('principal_id', $bob->principal_id)->orderBy('created_at')->orderByRaw('active_slot is not null')->get();
    expect($grants)->toHaveCount(2)
        ->and($grants[0]->id)->toBe($expiredGrantId)
        ->and($grants[0]->active_slot)->toBeNull()
        ->and($grants[1]->active_slot)->toBe(1);
});

test('only managers can change membership and invalid input is rejected', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $viewer = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    $membership = app(VaultMembership::class);
    $membership->grant($alice, $vault->id, $viewer->principal_id, 'viewer');
    $viewerGrant = DB::table('vault_grants')->where('principal_id', $viewer->principal_id)->whereNotNull('active_slot')->value('id');

    expect(fn () => $membership->grant($viewer, $vault->id, $bob->principal_id, 'viewer'))->toThrow(HttpException::class);
    expect(fn () => $membership->revoke($viewer, $vault->id, (string) $viewerGrant))->toThrow(HttpException::class);
    expect(fn () => $membership->grant($admin, $vault->id, $bob->principal_id, 'viewer'))->toThrow(HttpException::class);
    expect(fn () => $membership->grant($alice, $vault->id, $bob->principal_id, 'owner'))->toThrow(ValidationException::class);
    expect(fn () => $membership->grant($alice, $vault->id, (string) Str::uuid(), 'viewer'))->toThrow(ValidationException::class);
    expect(fn () => $membership->grant($alice, $vault->id, $bob->principal_id, 'viewer', now()->subDay()->toDateTimeString()))->toThrow(ValidationException::class);

    expect(DB::table('vault_grants')->where('principal_id', $bob->principal_id)->count())->toBe(0);
});

test('a manager cannot grant an expiry beyond their own authority', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $carol = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    $membership = app(VaultMembership::class);

    $membership->grant($alice, $vault->id, $bob->principal_id, 'manager', now()->addMinute()->toDateTimeString());

    expect(fn () => $membership->grant($bob, $vault->id, $carol->principal_id, 'viewer', now()->addDays(2)->toDateTimeString()))
        ->toThrow(ValidationException::class, 'cannot be later');
    expect(fn () => $membership->grant($bob, $vault->id, $carol->principal_id, 'viewer'))
        ->toThrow(ValidationException::class, 'cannot be later');
    expect(fn () => $membership->grant($bob, $vault->id, $carol->principal_id, 'manager'))
        ->toThrow(ValidationException::class, 'cannot be later');

    $membership->grant($bob, $vault->id, $carol->principal_id, 'viewer', now()->addSeconds(30)->toDateTimeString());
    $this->assertDatabaseHas('vault_grants', ['principal_id' => $carol->principal_id, 'permission' => 'viewer', 'active_slot' => 1]);
});

test('the final active manager grant cannot be revoked and inactive grants cannot be revoked twice', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    $membership = app(VaultMembership::class);
    $managerGrant = DB::table('vault_grants')->where('vault_id', $vault->id)->where('principal_id', $alice->principal_id)->whereNotNull('active_slot')->sole();

    expect(fn () => $membership->revoke($alice, $vault->id, (string) $managerGrant->id))
        ->toThrow(ValidationException::class, 'at least one active Manager');

    $membership->grant($alice, $vault->id, $admin->principal_id, 'manager');
    $membership->revoke($alice, $vault->id, (string) $managerGrant->id);

    expect(fn () => $membership->revoke($admin, $vault->id, (string) $managerGrant->id))
        ->toThrow(HttpException::class, 'already inactive');
});
