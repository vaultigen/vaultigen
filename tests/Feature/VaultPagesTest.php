<?php

use App\Filament\Pages\MyVaults;
use App\Filament\Pages\VaultEntries;
use App\Filament\Resources\Vaults\Pages\ListVaults;
use App\Filament\Resources\Vaults\Pages\ViewVault;
use App\Filament\Resources\Vaults\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Vaults\VaultResource;
use App\Models\Team;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultGrant;
use App\Services\PersonalVaults;
use App\Services\VaultAccess;
use App\Services\VaultAdministration;
use App\Services\VaultMembership;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('admins create edit and list vaults through the administration resource', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $this->actingAs($admin);

    Livewire::test(ListVaults::class)->assertSee('No vaults to administer')
        ->callAction('create', [
            'name' => 'Operations',
            'description' => 'Shared operational credentials.',
            'icon' => 'server-stack',
            'primary_custodian_user_id' => $custodian->id,
            'initial_manager_user_id' => $manager->id,
        ])->assertHasNoActionErrors()->assertNotified('Vault created');

    $vault = Vault::query()->sole();
    $this->assertDatabaseHas('vault_grants', ['vault_id' => $vault->id, 'principal_id' => $manager->principal_id, 'permission' => 'manager']);
    $this->get(VaultResource::getUrl('index'))->assertSee('Operations')->assertSee($custodian->name)->assertSee('Shared vault')->assertSeeHtml('fi-ta-col-has-column-url');

    Livewire::test(ListVaults::class)->callAction('create', [
        'name' => '',
        'primary_custodian_user_id' => $custodian->id,
        'initial_manager_user_id' => $manager->id,
    ])->assertHasActionErrors(['name']);
    expect(Vault::query()->count())->toBe(1);

    Livewire::test(ListVaults::class)->callTableAction('edit', $vault, [
        'name' => 'Renamed operations',
        'description' => null,
        'icon' => null,
    ])->assertHasNoTableActionErrors();

    expect($vault->fresh()->name)->toBe('Renamed operations')
        ->and($vault->fresh()->icon)->toBeNull();
});

test('ordinary users cannot open the administration resource and open forms are reauthorized', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);
    $this->get(VaultResource::getUrl('index'))->assertForbidden();
    Livewire::test(ListVaults::class)->assertForbidden();
    expect(fn () => app(VaultAdministration::class)->update($user, (string) Str::uuid(), [
        'name' => 'Nope',
        'description' => null,
        'icon' => null,
    ]))->toThrow(AuthorizationException::class);
});

test('revoking admin authority blocks vault writes and already open pages', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $this->actingAs($admin);
    $page = Livewire::test(ListVaults::class)->assertOk();
    DB::table('user_platform_roles')->where('user_id', $admin->id)->delete();

    expect(fn () => app(VaultAdministration::class)->create($admin, [
        'name' => 'Should not exist',
        'description' => null,
        'icon' => null,
        'primary_custodian_user_id' => $manager->id,
        'initial_manager_user_id' => $manager->id,
    ]))->toThrow(AuthorizationException::class);

    $page->call('$refresh')->assertForbidden();
    $this->get(VaultResource::getUrl('index'))->assertForbidden();
    expect(Vault::query()->count())->toBe(0);
});

test('the vault table shows a genuine empty state and only granted vaults', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice, ['name' => 'Operations']);

    $this->actingAs($bob);
    $this->get(MyVaults::getUrl())->assertOk()->assertSee('No vaults available yet');
    Livewire::test(MyVaults::class)->assertSee('No vaults available yet')->assertDontSee('Operations');

    $this->actingAs($alice);
    $personal = Vault::query()->findOrFail(app(PersonalVaults::class)->provisionFor($alice));
    Livewire::test(MyVaults::class)
        ->assertSee('Operations')
        ->assertSee($personal->name)
        ->assertSee('Shared vault')
        ->assertSee('Personal work vault')
        ->assertSee('Manager')
        ->assertSee('Open')
        ->assertSeeHtml('fi-ta-col-has-column-url');

    Livewire::test(MyVaults::class)
        ->set('tableFilters.kind.value', Vault::KIND_PRIVATE)
        ->assertSee($personal->name)
        ->assertDontSee('Operations');
});

test('opening a vault lands on its entries and hides organization details behind an about action', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice, ['name' => 'Operations']);
    $this->actingAs($alice);

    $this->get(VaultEntries::getUrl(['vault' => $vault->id]))
        ->assertOk()
        ->assertSee('Operations')
        ->assertSee('Manage members')
        ->assertSee('About this vault')
        ->assertDontSee('Why can I see this?');

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->mountAction('about')
        ->assertMountedActionModalSee('Why can I see this?')
        ->assertMountedActionModalSee($alice->name)
        ->assertMountedActionModalSee('Organization-managed vault');

    $personal = Vault::query()->findOrFail(app(PersonalVaults::class)->provisionFor($alice));

    Livewire::test(VaultEntries::class, ['vault' => $personal->id])
        ->mountAction('about')
        ->assertMountedActionModalSee('Personal work vault')
        ->assertMountedActionModalSee('Owner');
});

test('the vault pages have distinct navigation labels', function () {
    expect(MyVaults::getNavigationLabel())->toBe('My vaults')
        ->and(VaultResource::getNavigationLabel())->toBe('Vault administration');
});

test('the vault entries page is hidden from navigation and only managers see the member actions', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    app(VaultMembership::class)->grant($alice, $vault->id, $bob->principal_id, 'viewer');
    $this->actingAs($bob);

    expect(VaultEntries::shouldRegisterNavigation())->toBeFalse();
    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->assertActionHidden('members')
        ->mountAction('about')
        ->assertMountedActionModalSee('Your access: Viewer');
});

test('installation managers get an entry point to vault administration', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    Livewire::test(MyVaults::class)
        ->assertSee('Vault administration')
        ->assertSee(VaultResource::getUrl('index'), false);

    $ordinary = User::factory()->withTwoFactor()->create();
    $this->actingAs($ordinary);
    Livewire::test(MyVaults::class)->assertDontSee('Vault administration');
});

test('unauthorized or revoked vault identifiers are never exposed', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $carol = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice, ['name' => 'Operations']);
    $this->actingAs($carol);

    $this->get(VaultEntries::getUrl(['vault' => $vault->id]))->assertNotFound();
    $this->get(VaultEntries::getUrl())->assertNotFound();
    Livewire::test(VaultEntries::class, ['vault' => $vault->id])->assertNotFound();
    Livewire::test(MyVaults::class)->assertDontSee('Operations');

    $this->actingAs($alice);
    $page = Livewire::test(VaultEntries::class, ['vault' => $vault->id])->assertSee('About this vault');
    app(VaultMembership::class)->grant($alice, $vault->id, $carol->principal_id, 'manager');
    $aliceGrantId = DB::table('vault_grants')->where('vault_id', $vault->id)->where('principal_id', $alice->principal_id)->whereNotNull('active_slot')->value('id');
    app(VaultMembership::class)->revoke($carol, $vault->id, (string) $aliceGrantId);

    $page->call('$refresh')->assertNotFound();
});

test('the members relation manager labels team grants', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $vaultA = vaultigen_make_vault($admin, $alice, ['name' => 'Vault A']);
    $vaultB = vaultigen_make_vault($admin, $alice, ['name' => 'Vault B']);
    $team = Team::factory()->create(['name' => 'Operations']);
    $this->actingAs($alice);

    $page = Livewire::test(MembersRelationManager::class, ['ownerRecord' => $vaultA, 'pageClass' => ViewVault::class]);
    $page->callAction('grant', ['principal_id' => $team->principal_id, 'permission' => 'viewer', 'expires_at' => null])
        ->assertHasNoActionErrors();
    expect($page->instance()->getFilteredTableQuery()->pluck('principal_id')->all())->toContain($team->principal_id);
    $page->assertSee('Team: Operations');

    $other = Livewire::test(MembersRelationManager::class, ['ownerRecord' => $vaultB, 'pageClass' => ViewVault::class]);
    expect($other->instance()->getFilteredTableQuery()->where('principal_id', $team->principal_id)->count())->toBe(0);
});

test('the grant action offers the permission levels as radio choices', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    $this->actingAs($alice);

    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $vault, 'pageClass' => ViewVault::class])
        ->mountAction('grant')
        ->assertMountedActionModalSee('Viewer (metadata and reveal)')
        ->assertMountedActionModalSee('Editor (create and update)')
        ->assertMountedActionModalSee('Manager (full control)')
        ->assertMountedActionModalSeeHtml('fi-fo-radio');
});

test('managers add replace and revoke grants through the members relation manager', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    $this->actingAs($alice);

    $page = Livewire::test(MembersRelationManager::class, ['ownerRecord' => $vault, 'pageClass' => ViewVault::class])
        ->assertSee($alice->name)
        ->assertSee('Manager');

    $page->callAction('grant', ['principal_id' => $bob->principal_id, 'permission' => 'viewer', 'expires_at' => null])
        ->assertHasNoActionErrors()->assertNotified('Grant saved');
    $bobGrant = DB::table('vault_grants')->where('principal_id', $bob->principal_id)->whereNotNull('active_slot')->sole();
    expect($bobGrant->permission)->toBe('viewer');

    $page->callAction('grant', ['principal_id' => $bob->principal_id, 'permission' => 'editor', 'expires_at' => null])
        ->assertHasNoActionErrors();
    expect(DB::table('vault_grants')->where('principal_id', $bob->principal_id)->whereNotNull('active_slot')->value('permission'))->toBe('editor');
    expect(DB::table('vault_grants')->where('principal_id', $bob->principal_id)->whereNull('active_slot')->count())->toBe(1);

    $page->set('tableFilters', ['status' => ['value' => 'history']]);
    expect($page->instance()->getFilteredTableQuery()->count())->toBe(1);
    $page->set('tableFilters', ['status' => ['value' => 'active']]);
    expect($page->instance()->getFilteredTableQuery()->count())->toBe(2);
    $page->set('tableFilters', ['status' => ['value' => '']]);
    expect($page->instance()->getFilteredTableQuery()->count())->toBe(3);

    $grant = VaultGrant::query()->where('principal_id', $bob->principal_id)->whereNotNull('active_slot')->sole();
    $page->callTableAction('revoke', $grant)->assertHasNoTableActionErrors()->assertNotified('Grant revoked');
    $this->assertDatabaseHas('vault_grants', ['id' => $grant->id, 'active_slot' => null]);
});

test('the members relation manager refuses to revoke the final manager grant', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    $this->actingAs($alice);
    $grant = VaultGrant::query()->where('vault_id', $vault->id)->whereNotNull('active_slot')->sole();

    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $vault, 'pageClass' => ViewVault::class])
        ->callTableAction('revoke', $grant)
        ->assertNotified('Grant not revoked');
    $this->assertDatabaseHas('vault_grants', ['id' => $grant->id, 'active_slot' => 1]);
});

test('the administration resource requires a manager grant or governance role', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $alice = User::factory()->withTwoFactor()->create();
    $bob = User::factory()->withTwoFactor()->create();
    $viewer = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $alice);
    app(VaultMembership::class)->grant($alice, $vault->id, $viewer->principal_id, 'viewer');
    $other = vaultigen_make_vault($admin, $bob, ['name' => 'Other']);
    $this->actingAs($viewer);

    $this->get(VaultResource::getUrl('index'))->assertForbidden();
    Livewire::test(ListVaults::class)->assertForbidden();
    $this->get(VaultResource::getUrl('view', ['record' => $vault->id]))->assertNotFound();

    $this->actingAs($bob);
    Livewire::test(ViewVault::class, ['record' => $other->id])->assertOk();
    $this->get(VaultResource::getUrl('view', ['record' => $vault->id]))->assertNotFound();
});

test('vault creators create a vault from the vault list and land on it', function () {
    $creator = User::factory()->vaultCreator()->withTwoFactor()->create();
    $this->actingAs($creator);

    Livewire::test(MyVaults::class)->assertSee('New vault');

    $page = Livewire::test(MyVaults::class)->callAction('create', [
        'name' => 'Acme – Website',
        'description' => 'Customer project credentials.',
        'icon' => 'globe-alt',
    ])->assertHasNoActionErrors();

    $vault = Vault::query()->sole();
    expect($vault->primary_custodian_user_id)->toBe($creator->id)
        ->and($vault->description)->toBe('Customer project credentials.')
        ->and(app(VaultAccess::class)->permission($creator, $vault->id))->toBe('manager');
    $page->assertRedirect(VaultEntries::getUrl(['vault' => $vault->id]));
});

test('the new vault action is hidden without the creation ability', function () {
    $ordinary = User::factory()->withTwoFactor()->create();
    $this->actingAs($ordinary);

    Livewire::test(MyVaults::class)->assertDontSee('New vault');
});

test('the vault detail shows lifecycle states', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager, ['name' => 'Lifecycle']);
    $this->actingAs($admin);

    app(VaultAdministration::class)->setState($admin, $vault->id, 'read_only');
    Livewire::test(ViewVault::class, ['record' => $vault->id])->assertSee('Read Only');

    app(VaultAdministration::class)->initiatePurge($admin, $vault->id);
    Livewire::test(ViewVault::class, ['record' => $vault->id])->assertSee('Pending Purge');
});

test('custodians review membership from the vault without the ability to change it', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager, ['primary_custodian_user_id' => $custodian->id]);
    $this->actingAs($custodian);

    Livewire::test(ViewVault::class, ['record' => $vault->id])
        ->assertOk()
        ->assertSee($manager->name)
        ->assertDontSee('Add or replace grant');

    $this->actingAs($manager);
    Livewire::test(ViewVault::class, ['record' => $vault->id])->assertSee('Add or replace grant');
});

test('custodians govern their vaults from the administration resource', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $newCustodian = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager, ['name' => 'Governed project', 'primary_custodian_user_id' => $custodian->id]);
    $this->actingAs($custodian);

    $page = Livewire::test(ListVaults::class)->assertSee('Governed project')->assertSee($custodian->name);
    $page->callTableAction('edit', $vault, ['name' => 'Renamed project', 'description' => null, 'icon' => null])
        ->assertHasNoTableActionErrors();
    expect($vault->fresh()->name)->toBe('Renamed project');

    $page->callTableAction('archive', $vault)->assertNotified('Vault archived');
    expect($vault->fresh()->state)->toBe('archived');
    $page->callTableAction('reactivate', $vault)->assertNotified('Vault reactivated');
    expect($vault->fresh()->state)->toBe('active');

    $page->callTableAction('transfer', $vault, ['primary_custodian_user_id' => $newCustodian->id])->assertHasNoTableActionErrors();
    expect($vault->fresh()->primary_custodian_user_id)->toBe($newCustodian->id);

    $this->actingAs($newCustodian);
    Livewire::test(ListVaults::class)->callTableAction('purge', $vault)->assertNotified('Vault marked pending purge');
    expect($vault->fresh()->state)->toBe('pending_purge');
});

test('administrators govern every vault and recover a vault with no active manager', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $newManager = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager, ['name' => 'Stranded project']);
    DB::table('vault_grants')->where('vault_id', $vault->id)->update(['active_slot' => null, 'revoked_at' => now()]);
    $this->actingAs($admin);

    Livewire::test(ListVaults::class)->assertSee('Stranded project');
    $code = AppAuthentication::make()->getCurrentCode($admin, $admin->app_authentication_secret);
    Livewire::test(ListVaults::class)->callTableAction('recover', $vault, [
        'initial_manager_user_id' => $newManager->id,
        'reason' => 'The sole Manager left the company.',
        'password' => 'password',
        'code' => $code,
    ])->assertHasNoTableActionErrors();

    expect(app(VaultAccess::class)->permission($newManager, $vault->id))->toBe('manager');
});

test('ordinary users cannot open vault administration', function () {
    $ordinary = User::factory()->withTwoFactor()->create();
    $this->actingAs($ordinary);

    $this->get(VaultResource::getUrl('index'))->assertForbidden();
    Livewire::test(ListVaults::class)->assertForbidden();
});

test('vault managers edit metadata from the vault entries page', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    $vault = vaultigen_make_vault($admin, $manager, ['name' => 'Before rename']);
    $this->actingAs($manager);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->callAction('edit', ['name' => 'After rename', 'description' => null, 'icon' => null])
        ->assertHasNoActionErrors();

    expect($vault->fresh()->name)->toBe('After rename');
});

test('guests are redirected away from vault administration', function () {
    $this->get(VaultResource::getUrl('index'))->assertRedirect();
});
