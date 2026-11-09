<?php

use App\Filament\Livewire\OffboardingReport;
use App\Filament\Pages\Offboarding;
use App\Filament\Pages\Settings;
use App\Models\User;
use App\Models\Vault;
use App\Services\EntryVault;
use App\Services\InstallationSettings;
use App\Services\Offboarding as OffboardingService;
use App\Services\PersonalVaults;
use App\Services\ScopedGrants;
use App\Services\SecretReveals;
use App\Services\VaultAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * @return array{admin: User, target: User, newOwner: User, custodian: User, personal: Vault, shared: Vault}
 */
function vaultigen_offboarding_fixture(): array
{
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $target = User::factory()->withTwoFactor()->create();
    $newOwner = User::factory()->withTwoFactor()->create();
    $custodian = User::factory()->withTwoFactor()->create();
    $personal = Vault::query()->findOrFail(app(PersonalVaults::class)->provisionFor($target));
    $shared = vaultigen_make_vault($admin, $target, ['name' => 'Shared project', 'primary_custodian_user_id' => $target->id]);

    return compact('admin', 'target', 'newOwner', 'custodian', 'personal', 'shared');
}

test('secret deliveries are tracked per user without storing values', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);

    app(EntryVault::class)->reveal($alice, $entry->id, 'password');
    app(EntryVault::class)->reveal($alice, $entry->id, 'password');

    $tracking = DB::table('secret_reveals')->where('user_id', $alice->id)->where('entry_id', $entry->id)->sole();
    expect((int) $tracking->reveal_count)->toBe(2);

    $report = app(SecretReveals::class)->forUser($alice);
    expect($report)->toHaveCount(1)
        ->and($report[0]['entry_id'])->toBe($entry->id)
        ->and($report[0]['title'])->toBe('Example login')
        ->and($report[0]['vault_name'])->toBe($vault->name)
        ->and(array_key_exists('password', $report[0]))->toBeFalse();
});

test('offboarding converts owned personal vaults to shared vaults and hands over custodians', function () {
    ['admin' => $admin, 'target' => $target, 'newOwner' => $newOwner, 'custodian' => $custodian, 'personal' => $personal, 'shared' => $shared] = vaultigen_offboarding_fixture();
    DB::table('sessions')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $target->id,
        'payload' => 'x',
        'last_activity' => now()->timestamp,
    ]);
    $folder = vaultigen_make_folder($target, $personal, 'Personal folder');
    $entry = vaultigen_make_entry($target, $personal, ['folder_id' => $folder->id]);
    app(ScopedGrants::class)->grantOnFolder($target, $folder->id, $target->principal_id, 'viewer');
    app(ScopedGrants::class)->grantOnEntry($target, $entry->id, $target->principal_id, 'viewer');

    app(OffboardingService::class)->offboard($admin, $target, [
        'personal' => [['vault_id' => $personal->id, 'decision' => 'convert', 'custodian_user_id' => $custodian->id, 'manager_user_id' => $newOwner->id]],
        'custodian' => [['vault_id' => $shared->id, 'transfer_user_id' => $custodian->id]],
    ]);

    expect($personal->fresh()->kind)->toBe(Vault::KIND_SHARED)
        ->and($personal->fresh()->private_workspace_owner_user_id)->toBeNull()
        ->and($personal->fresh()->primary_custodian_user_id)->toBe($custodian->id)
        ->and($personal->fresh()->state)->toBe('active')
        ->and($shared->fresh()->primary_custodian_user_id)->toBe($custodian->id)
        ->and($target->fresh()->is_active)->toBeFalse();

    expect(app(VaultAccess::class)->permission($newOwner, $personal->id))->toBe('manager')
        ->and(app(VaultAccess::class)->permission($target, $personal->id))->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'action' => 'user.offboarded']);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'action' => 'vault.converted']);
});

test('offboarding archives or marks personal vaults for retention-bound purge', function () {
    ['admin' => $admin, 'newOwner' => $newOwner, 'custodian' => $custodian] = vaultigen_offboarding_fixture();
    $archiveTarget = User::factory()->withTwoFactor()->create();
    $archiveVault = Vault::query()->findOrFail(app(PersonalVaults::class)->provisionFor($archiveTarget));
    $purgeTarget = User::factory()->withTwoFactor()->create();
    $purgeVault = Vault::query()->findOrFail(app(PersonalVaults::class)->provisionFor($purgeTarget));

    $service = app(OffboardingService::class);
    $service->offboard($admin, $archiveTarget, [
        'personal' => [['vault_id' => $archiveVault->id, 'decision' => 'archive']],
    ]);
    expect($archiveVault->fresh()->state)->toBe('archived');

    $service->offboard($admin, $purgeTarget, [
        'personal' => [['vault_id' => $purgeVault->id, 'decision' => 'purge']],
    ]);
    expect($purgeVault->fresh()->state)->toBe('pending_purge')
        ->and($purgeVault->fresh()->purge_after)->not->toBeNull();
});

test('offboarding rejects missing or invalid decisions', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $target = User::factory()->withTwoFactor()->create();
    $personal = Vault::query()->findOrFail(app(PersonalVaults::class)->provisionFor($target));
    $newOwner = User::factory()->withTwoFactor()->create();
    $service = app(OffboardingService::class);

    expect(fn () => $service->offboard($admin, $target, ['personal' => []]))->toThrow(ValidationException::class);
    expect(fn () => $service->offboard($admin, $target, ['personal' => ['bogus']]))->toThrow(ValidationException::class);
    expect(fn () => $service->offboard($admin, $target, [
        'personal' => [['vault_id' => $personal->id, 'decision' => 'not-a-decision']],
    ]))->toThrow(ValidationException::class);

    // Converting requires both a new Custodian and a new Manager.
    expect(fn () => $service->offboard($admin, $target, [
        'personal' => [['vault_id' => $personal->id, 'decision' => 'convert', 'manager_user_id' => $newOwner->id]],
    ]))->toThrow(ValidationException::class);
    expect(fn () => $service->offboard($admin, $target, [
        'personal' => [['vault_id' => $personal->id, 'decision' => 'convert', 'custodian_user_id' => $newOwner->id]],
    ]))->toThrow(ValidationException::class);

    // The account being offboarded cannot become the Custodian or the Manager.
    expect(fn () => $service->offboard($admin, $target, [
        'personal' => [['vault_id' => $personal->id, 'decision' => 'convert', 'custodian_user_id' => $target->id, 'manager_user_id' => $newOwner->id]],
    ]))->toThrow(ValidationException::class);
    expect(fn () => $service->offboard($admin, $target, [
        'personal' => [['vault_id' => $personal->id, 'decision' => 'convert', 'custodian_user_id' => $newOwner->id, 'manager_user_id' => $target->id]],
    ]))->toThrow(ValidationException::class);

    // An inactive recipient is refused.
    DB::table('users')->where('id', $newOwner->id)->update(['is_active' => false]);
    expect(fn () => $service->offboard($admin, $target, [
        'personal' => [['vault_id' => $personal->id, 'decision' => 'convert', 'custodian_user_id' => $newOwner->id, 'manager_user_id' => $newOwner->id]],
    ]))->toThrow(ValidationException::class);

    // A missing custodian decision is refused even when personal decisions are set.
    ['admin' => $custodianAdmin, 'target' => $custodianTarget, 'personal' => $custodianPersonal, 'shared' => $custodianShared] = vaultigen_offboarding_fixture();
    expect(fn () => $service->offboard($custodianAdmin, $custodianTarget, [
        'personal' => [['vault_id' => $custodianPersonal->id, 'decision' => 'archive']],
    ]))->toThrow(ValidationException::class);
    expect($custodianShared->fresh()->primary_custodian_user_id)->toBe($custodianTarget->id);
});

test('offboarding refuses archive, purge, or convert for vaults already pending purge', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $service = app(OffboardingService::class);

    $archiveTarget = User::factory()->withTwoFactor()->create();
    $archiveVault = Vault::query()->findOrFail(app(PersonalVaults::class)->provisionFor($archiveTarget));
    DB::table('vaults')->where('id', $archiveVault->id)->update(['state' => 'pending_purge']);
    expect(fn () => $service->offboard($admin, $archiveTarget, [
        'personal' => [['vault_id' => $archiveVault->id, 'decision' => 'archive']],
    ]))->toThrow(ValidationException::class);

    $purgeTarget = User::factory()->withTwoFactor()->create();
    $purgeVault = Vault::query()->findOrFail(app(PersonalVaults::class)->provisionFor($purgeTarget));
    DB::table('vaults')->where('id', $purgeVault->id)->update(['state' => 'pending_purge']);
    expect(fn () => $service->offboard($admin, $purgeTarget, [
        'personal' => [['vault_id' => $purgeVault->id, 'decision' => 'purge']],
    ]))->toThrow(ValidationException::class);

    $convertTarget = User::factory()->withTwoFactor()->create();
    $convertVault = Vault::query()->findOrFail(app(PersonalVaults::class)->provisionFor($convertTarget));
    $custodian = User::factory()->withTwoFactor()->create();
    $manager = User::factory()->withTwoFactor()->create();
    DB::table('vaults')->where('id', $convertVault->id)->update(['state' => 'pending_purge']);
    expect(fn () => $service->offboard($admin, $convertTarget, [
        'personal' => [['vault_id' => $convertVault->id, 'decision' => 'convert', 'custodian_user_id' => $custodian->id, 'manager_user_id' => $manager->id]],
    ]))->toThrow(ValidationException::class);
});

test('offboarding guards self and non-administrators', function () {
    ['admin' => $admin, 'target' => $target] = vaultigen_offboarding_fixture();
    $service = app(OffboardingService::class);

    expect(fn () => $service->offboard($admin, $admin, []))->toThrow(ValidationException::class);
    expect(fn () => $service->offboard($target, $target, []))->toThrow(AuthorizationException::class);
});

test('the offboarding page runs the decisions and shows the rotation report', function () {
    ['admin' => $admin, 'target' => $target, 'newOwner' => $newOwner, 'custodian' => $custodian, 'personal' => $personal, 'shared' => $shared] = vaultigen_offboarding_fixture();
    $entry = vaultigen_make_entry($target, $shared, ['title' => 'Vendor portal']);
    app(EntryVault::class)->reveal($target, $entry->id, 'password');
    $this->actingAs($admin);

    $page = Livewire::test(Offboarding::class)
        ->assertOk()
        ->fillForm(['user_id' => $target->id]);
    $page->assertSet('data.personal.0.vault_id', $personal->id)
        ->assertFormFieldHidden('personal.0.custodian_user_id')
        ->assertFormFieldHidden('personal.0.manager_user_id')
        ->set('data.personal.0.decision', 'convert')
        ->assertFormFieldVisible('personal.0.custodian_user_id')
        ->assertFormFieldVisible('personal.0.manager_user_id')
        ->set('data.personal.0.custodian_user_id', $custodian->id)
        ->set('data.personal.0.manager_user_id', $newOwner->id)
        ->set('data.custodian.0.transfer_user_id', $custodian->id)
        ->call('offboard')
        ->assertNotified(__('offboarding.notifications.offboarded', ['name' => $target->name]));

    expect($target->fresh()->is_active)->toBeFalse();

    Livewire::test(OffboardingReport::class, ['user' => $target->id])
        ->assertSee('Vendor portal')
        ->assertDontSee('correct-horse-battery-staple-42')
        ->call('$refresh')
        ->assertOk();
    Livewire::test(OffboardingReport::class)->assertOk();
});

test('the offboarding page reports missing selections and validation failures', function () {
    ['admin' => $admin, 'target' => $target] = vaultigen_offboarding_fixture();
    $this->actingAs($admin);

    Livewire::test(Offboarding::class)
        ->call('offboard')
        ->assertNotified(__('offboarding.notifications.not_offboarded'));

    Livewire::test(Offboarding::class)
        ->set('data.user_id', (string) Str::uuid())
        ->call('offboard')
        ->assertNotified(__('offboarding.notifications.not_offboarded'));

    Livewire::test(Offboarding::class)
        ->fillForm(['user_id' => $target->id])
        ->set('data.personal', [])
        ->set('data.custodian', [])
        ->call('offboard')
        ->assertNotified(__('offboarding.notifications.not_offboarded'));
});

test('the offboarding page preselects the account from the user query parameter', function () {
    ['admin' => $admin, 'target' => $target, 'personal' => $personal, 'shared' => $shared] = vaultigen_offboarding_fixture();
    $this->actingAs($admin);

    $page = Livewire::test(Offboarding::class, ['user' => $target->id])
        ->assertSet('data.user_id', $target->id);

    $personalRows = array_values($page->get('data.personal'));
    $custodianRows = array_values($page->get('data.custodian'));
    expect($personalRows)->toHaveCount(1)
        ->and($personalRows[0]['vault_id'])->toBe($personal->id)
        ->and($custodianRows)->toHaveCount(1)
        ->and($custodianRows[0]['vault_id'])->toBe($shared->id);

    Livewire::test(Offboarding::class, ['user' => $admin->id])
        ->assertSet('data.user_id', null);

    Livewire::test(Offboarding::class, ['user' => (string) Str::uuid()])
        ->assertSet('data.user_id', null);
});

test('ordinary users cannot open the offboarding page', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    $this->get(Offboarding::getUrl())->assertForbidden();
    Livewire::test(Offboarding::class)->assertForbidden();
});

test('retention settings persist and validate', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);

    Livewire::test(Settings::class)
        ->assertSet('data.trash_retention_days', 30)
        ->assertSet('data.revision_retention_count', 50)
        ->assertSet('data.revision_retention_days', 365)
        ->fillForm([
            'trash_retention_days' => 7,
            'revision_retention_count' => 10,
            'revision_retention_days' => 90,
        ])
        ->call('save')->assertHasNoFormErrors()->assertNotified('Settings saved');

    $values = app(InstallationSettings::class)->values();
    expect($values['trash_retention_days'])->toBe(7)
        ->and($values['revision_retention_count'])->toBe(10)
        ->and($values['revision_retention_days'])->toBe(90);

    foreach ([['trash_retention_days' => 0], ['revision_retention_count' => 1001], ['revision_retention_days' => 3651]] as $changes) {
        Livewire::test(Settings::class)->fillForm($changes)->call('save')->assertHasFormErrors([array_key_first($changes)]);
    }
});
