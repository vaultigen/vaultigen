<?php

use App\Filament\Pages\ScopedAccess;
use App\Models\EntryGrant;
use App\Models\FolderGrant;
use App\Models\Team;
use App\Models\User;
use App\Services\ScopedGrants;
use App\Services\VaultAdministration;
use App\Services\VaultMembership;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('folder grants replace the active grant and keep history', function () {
    ['alice' => $alice, 'dave' => $dave, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    $grants = app(ScopedGrants::class);

    $grants->grantOnFolder($alice, $folderA->id, $dave->principal_id, 'viewer');
    expect(DB::table('folder_grants')->where('folder_id', $folderA->id)->where('principal_id', $dave->principal_id)->whereNotNull('active_slot')->count())->toBe(1);

    $grants->grantOnFolder($alice, $folderA->id, $dave->principal_id, 'manager');
    expect(DB::table('folder_grants')->where('folder_id', $folderA->id)->where('principal_id', $dave->principal_id)->whereNotNull('active_slot')->count())->toBe(1)
        ->and(DB::table('folder_grants')->where('folder_id', $folderA->id)->where('principal_id', $dave->principal_id)->whereNull('active_slot')->count())->toBe(1);

    $active = FolderGrant::query()->where('folder_id', $folderA->id)->where('principal_id', $dave->principal_id)->whereNotNull('active_slot')->sole();
    $grants->revokeFolder($alice, $folderA->id, (string) $active->id);

    expect(DB::table('folder_grants')->where('folder_id', $folderA->id)->where('principal_id', $dave->principal_id)->whereNotNull('active_slot')->count())->toBe(0);
    $this->assertDatabaseHas('audit_events', ['action' => 'grant.created']);
    $this->assertDatabaseHas('audit_events', ['action' => 'grant.revoked']);
});

test('entry grants can be created and revoked with guards', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'dave' => $dave, 'vault' => $vault, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $grants = app(ScopedGrants::class);

    expect(fn () => $grants->grantOnEntry($bob, $entryA->id, $dave->principal_id, 'viewer'))->toThrow(AuthorizationException::class);
    expect(fn () => $grants->grantOnEntry($alice, $entryA->id, $dave->principal_id, 'viewer', now()->addMinute()->toDateTimeString()))->not->toThrow(AuthorizationException::class);
    expect(fn () => $grants->grantOnEntry($alice, $entryA->id, $dave->principal_id, 'viewer', now()->addDay()->toDateTimeString()))->not->toThrow(AuthorizationException::class);
    expect(fn () => $grants->grantOnEntry($alice, $entryA->id, 'not-a-uuid', 'viewer'))->toThrow(ValidationException::class);
    expect(fn () => $grants->grantOnEntry($alice, $entryA->id, $dave->principal_id, 'owner'))->toThrow(ValidationException::class);
    expect(fn () => $grants->grantOnEntry($alice, $entryA->id, $dave->principal_id, 'viewer', now()->subMinute()->toDateTimeString()))->toThrow(ValidationException::class);

    $active = EntryGrant::query()->where('entry_id', $entryA->id)->where('principal_id', $dave->principal_id)->whereNotNull('active_slot')->sole();
    expect(fn () => $grants->revokeEntry($bob, $entryA->id, (string) $active->id))->toThrow(AuthorizationException::class);
    $grants->revokeEntry($alice, $entryA->id, (string) $active->id);
    expect(fn () => $grants->revokeEntry($alice, $entryA->id, (string) $active->id))->toThrow(HttpException::class);
    expect(fn () => $grants->revokeEntry($alice, $entryA->id, (string) Str::uuid()))->toThrow(NotFoundHttpException::class);
});

test('scoped grants cannot change when the vault is not active', function () {
    ['admin' => $admin, 'alice' => $alice, 'bob' => $bob, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    app(VaultAdministration::class)->setState($admin, $vault->id, 'read_only');

    expect(fn () => app(ScopedGrants::class)->grantOnFolder($alice, $folderA->id, $bob->principal_id, 'viewer'))->toThrow(ValidationException::class);
});

test('the scoped access page manages folder grants', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    $this->actingAs($alice);

    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'folder', 'target' => $folderA->id])
        ->assertSee('Edit folder')
        ->callAction(TestAction::make('grant')->table(), ['principal_id' => $carol->principal_id, 'permission' => 'viewer'])
        ->assertNotified('Grant saved');

    $grant = FolderGrant::query()->where('folder_id', $folderA->id)->where('principal_id', $carol->principal_id)->whereNotNull('active_slot')->sole();

    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'folder', 'target' => $folderA->id])
        ->callAction(TestAction::make('revoke')->table($grant))
        ->assertNotified('Grant revoked');

    expect(FolderGrant::query()->find($grant->id)->active_slot)->toBeNull();

    $this->actingAs($carol);
    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'folder', 'target' => $folderA->id])->assertNotFound();
});

test('renaming is ignored on a non-folder target', function () {
    ['alice' => $alice, 'vault' => $vault, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $this->actingAs($alice);

    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'entry', 'target' => $entryA->id])
        ->call('renameFolder')
        ->assertOk();
});

test('whole-vault access lists vault holders and excludes direct grants', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    $this->actingAs($alice);

    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'folder', 'target' => $folderA->id])
        ->callAction(TestAction::make('grant')->table(), ['principal_id' => $carol->principal_id, 'permission' => 'viewer'])
        ->assertNotified('Grant saved')
        ->assertViewHas('vaultAccess', fn (array $rows): bool => collect($rows)->contains(
            fn (array $row): bool => $row['principal_id'] === $alice->principal_id,
        ) && ! collect($rows)->contains(
            fn (array $row): bool => in_array($row['principal_id'], [$bob->principal_id, $carol->principal_id], true),
        ));
});

test('the scoped access page renames a folder and surfaces validation failures', function () {
    ['alice' => $alice, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    $this->actingAs($alice);

    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'folder', 'target' => $folderA->id])
        ->assertSee('Edit folder')
        ->assertSet('folderName', 'Operations')
        ->set('folderName', 'Operations & support')
        ->call('renameFolder')
        ->assertNotified('Folder renamed')
        ->assertSet('folderName', 'Operations & support');

    expect($folderA->fresh()->name)->toBe('Operations & support');

    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'folder', 'target' => $folderA->id])
        ->set('folderName', 'Servers')
        ->call('renameFolder')
        ->assertNotified('Folder not renamed');

    expect($folderA->fresh()->name)->toBe('Operations & support');
});

test('the scoped access page manages entry grants and validates the target', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault, 'entryA' => $entryA, 'folderB' => $folderB] = vaultigen_scope_fixture();
    $this->actingAs($alice);

    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'entry', 'target' => $entryA->id])
        ->callAction(TestAction::make('grant')->table(), ['principal_id' => $bob->principal_id, 'permission' => 'editor'])
        ->assertNotified('Grant saved');

    $grant = EntryGrant::query()->where('entry_id', $entryA->id)->where('principal_id', $bob->principal_id)->whereNotNull('active_slot')->sole();

    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'entry', 'target' => $entryA->id])
        ->callAction(TestAction::make('revoke')->table($grant))
        ->assertNotified('Grant revoked');

    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'bogus', 'target' => $entryA->id])->assertNotFound();
    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'entry', 'target' => (string) Str::uuid()])->assertNotFound();
    Livewire::test(ScopedAccess::class, ['vault' => '', 'kind' => 'folder', 'target' => $folderB->id])->assertNotFound();
});

test('the scoped access page keeps granting scoped to managers', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    $this->actingAs($alice);
    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'folder', 'target' => $folderA->id])
        ->callAction(TestAction::make('grant')->table(), ['principal_id' => $bob->principal_id, 'permission' => 'viewer']);

    $this->actingAs($bob);
    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'folder', 'target' => $folderA->id])->assertNotFound();
});

test('scoped access filters active and history grants', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    $this->actingAs($alice);

    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'folder', 'target' => $folderA->id])
        ->filterTable('status', 'active')
        ->assertSee('Active');

    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'folder', 'target' => $folderA->id])
        ->filterTable('status', 'history')
        ->assertOk();
});

test('a time-limited manager cannot grant access that outlives their own', function () {
    ['alice' => $alice, 'dave' => $dave, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    $eve = User::factory()->withTwoFactor()->create();
    app(VaultMembership::class)->grant($alice, $vault->id, $eve->principal_id, 'manager', now()->addMinutes(5)->toDateTimeString());

    expect(fn () => app(ScopedGrants::class)->grantOnFolder($eve, $folderA->id, $dave->principal_id, 'viewer', now()->addDay()->toDateTimeString()))
        ->toThrow(ValidationException::class);

    $this->actingAs($eve);
    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'folder', 'target' => $folderA->id])
        ->callAction(TestAction::make('grant')->table(), [
            'principal_id' => $dave->principal_id,
            'permission' => 'viewer',
            'expires_at' => now()->addDay()->toDateTimeString(),
        ])
        ->assertNotified('Grant not saved');
});

test('revoking surfaces a validation failure and team labels render', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    $team = Team::factory()->create(['name' => 'Operations team']);
    app(ScopedGrants::class)->grantOnFolder($alice, $folderA->id, $team->principal_id, 'viewer');
    $this->actingAs($alice);

    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'folder', 'target' => $folderA->id])
        ->assertSee('Team: Operations team');

    app(VaultAdministration::class)->setState($alice, $vault->id, 'read_only');
    $active = FolderGrant::query()->where('folder_id', $folderA->id)->where('principal_id', $bob->principal_id)->whereNotNull('active_slot')->sole();

    Livewire::test(ScopedAccess::class, ['vault' => $vault->id, 'kind' => 'folder', 'target' => $folderA->id])
        ->callAction(TestAction::make('revoke')->table($active))
        ->assertNotified('Grant not revoked');
});
