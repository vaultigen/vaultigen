<?php

use App\Mail\InvitationMail;
use App\Models\User;
use App\Models\Vault;
use App\Services\Invitations;
use App\Services\PersonalVaults;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

test('each user receives exactly one organization-managed personal work vault', function () {
    $owner = User::factory()->withTwoFactor()->create(['name' => 'Alice Example']);
    $service = app(PersonalVaults::class);

    $id = $service->provisionFor($owner);
    $vault = Vault::query()->findOrFail($id);

    expect($vault->kind)->toBe('private_workspace')
        ->and($vault->name)->toBe("Alice Example's work vault")
        ->and($vault->private_workspace_owner_user_id)->toBe($owner->id)
        ->and($vault->primary_custodian_user_id)->toBe($owner->id)
        ->and($vault->created_by_user_id)->toBe($owner->id)
        ->and($vault->description)->toContain('Organization-managed')
        ->and($service->provisionFor($owner))->toBe($id)
        ->and(Vault::query()->where('private_workspace_owner_user_id', $owner->id)->count())->toBe(1);

    $this->assertDatabaseHas('vault_key_versions', ['vault_id' => $id, 'version' => 1]);
    $this->assertDatabaseHas('vault_grants', [
        'vault_id' => $id,
        'principal_id' => $owner->principal_id,
        'permission' => 'manager',
        'active_slot' => 1,
    ]);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $owner->id, 'action' => 'vault.created']);
});

test('the provisioning command covers every existing user exactly once', function () {
    $users = User::factory()->withTwoFactor()->count(3)->create();

    $this->artisan('vaultigen:provision-personal-vaults')
        ->expectsOutput('Personal work vaults ensured for 3 users.')
        ->assertSuccessful();
    $this->artisan('vaultigen:provision-personal-vaults')
        ->expectsOutput('Personal work vaults ensured for 3 users.')
        ->assertSuccessful();

    expect(Vault::query()->where('kind', 'private_workspace')->count())->toBe(3);
    foreach ($users as $user) {
        expect(Vault::query()->where('private_workspace_owner_user_id', $user->id)->count())->toBe(1);
    }
});

test('accepting an invitation provisions the personal work vault during onboarding', function () {
    Mail::fake();
    config(['mail.default' => 'smtp']);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    app(Invitations::class)->invite($admin, 'dana@example.test');
    $token = Mail::sent(InvitationMail::class)->sole()->token;

    $user = app(Invitations::class)->accept($token, 'dana@example.test', 'Dana', 'a long synthetic password');

    $vault = Vault::query()->where('private_workspace_owner_user_id', $user->id)->sole();
    expect($vault->kind)->toBe('private_workspace')
        ->and($vault->private_workspace_owner_user_id)->toBe($user->id);
    $this->assertDatabaseHas('vault_grants', [
        'vault_id' => $vault->id,
        'principal_id' => $user->principal_id,
        'permission' => 'manager',
        'active_slot' => 1,
    ]);
});

test('the database enforces one personal workspace per owner', function () {
    $owner = User::factory()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $insert = function () use ($owner, $custodian): void {
        DB::table('vaults')->insert([
            'id' => (string) Str::uuid(),
            'name' => 'Duplicate',
            'normalized_name' => 'duplicate',
            'slug' => 'duplicate-'.Str::lower(Str::random(6)),
            'kind' => 'private_workspace',
            'private_workspace_owner_user_id' => $owner->id,
            'primary_custodian_user_id' => $custodian->id,
            'state' => 'active',
            'created_by_user_id' => $custodian->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $insert();
    expect(fn () => DB::transaction($insert))->toThrow(QueryException::class);
    expect(Vault::query()->where('private_workspace_owner_user_id', $owner->id)->count())->toBe(1);
});

test('personal vault provisioning rolls back when its vault row cannot be written', function () {
    $owner = User::factory()->withTwoFactor()->create();
    $decoy = User::factory()->withTwoFactor()->create();
    DB::table('vaults')->insert([
        'id' => (string) Str::uuid(),
        'name' => 'Decoy',
        'normalized_name' => 'decoy',
        'slug' => 'personal-work-vault-'.substr(hash('sha256', $owner->id), 0, 16),
        'kind' => 'shared',
        'primary_custodian_user_id' => $decoy->id,
        'state' => 'active',
        'created_by_user_id' => $decoy->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(PersonalVaults::class)->provisionFor($owner))->toThrow(QueryException::class);

    expect(Vault::query()->count())->toBe(1)
        ->and(Vault::query()->where('private_workspace_owner_user_id', $owner->id)->doesntExist())->toBeTrue()
        ->and(DB::table('vault_key_versions')->count())->toBe(0)
        ->and(DB::table('vault_grants')->count())->toBe(0);
});
