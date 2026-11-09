<?php

use App\Filament\FavoriteVaultNavigation;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EntryDetail;
use App\Filament\Pages\MyVaults;
use App\Filament\Pages\VaultEntries;
use App\Models\User;
use App\Services\Favorites;
use Filament\Navigation\NavigationItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('users can favorite and unfavorite a vault', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $favorites = app(Favorites::class);

    expect($favorites->isVaultFavorite($alice, $vault->id))->toBeFalse()
        ->and($favorites->toggleVault($alice, $vault->id))->toBeTrue()
        ->and($favorites->isVaultFavorite($alice, $vault->id))->toBeTrue();

    $this->assertDatabaseHas('favorite_vaults', ['user_id' => $alice->id, 'vault_id' => $vault->id]);

    expect($favorites->toggleVault($alice, $vault->id))->toBeFalse()
        ->and($favorites->isVaultFavorite($alice, $vault->id))->toBeFalse();

    $this->assertDatabaseMissing('favorite_vaults', ['user_id' => $alice->id, 'vault_id' => $vault->id]);
});

test('users can favorite and unfavorite an entry', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $favorites = app(Favorites::class);

    expect($favorites->isEntryFavorite($alice, $entry->id))->toBeFalse()
        ->and($favorites->toggleEntry($alice, $entry->id))->toBeTrue()
        ->and($favorites->isEntryFavorite($alice, $entry->id))->toBeTrue();

    $this->assertDatabaseHas('favorite_entries', ['user_id' => $alice->id, 'entry_id' => $entry->id]);

    expect($favorites->toggleEntry($alice, $entry->id))->toBeFalse()
        ->and($favorites->isEntryFavorite($alice, $entry->id))->toBeFalse();

    $this->assertDatabaseMissing('favorite_entries', ['user_id' => $alice->id, 'entry_id' => $entry->id]);
});

test('favoriting an unreachable vault or entry is rejected', function () {
    ['alice' => $alice, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $favorites = app(Favorites::class);

    expect(fn () => $favorites->toggleVault($carol, $vault->id))->toThrow(HttpException::class);
    expect(fn () => $favorites->toggleEntry($carol, $entry->id))->toThrow(HttpException::class);

    $this->assertDatabaseCount('favorite_vaults', 0);
    $this->assertDatabaseCount('favorite_entries', 0);
});

test('favorite vaults are filtered to what the user can see and sorted by name', function () {
    ['admin' => $admin, 'alice' => $alice, 'bob' => $bob] = vaultigen_entry_fixture();
    $beta = vaultigen_make_vault($admin, $alice, ['name' => 'Beta']);
    $alpha = vaultigen_make_vault($admin, $alice, ['name' => 'Alpha']);
    $hidden = vaultigen_make_vault($admin, $bob, ['name' => 'Gamma']);
    $favorites = app(Favorites::class);

    $favorites->toggleVault($alice, $beta->id);
    $favorites->toggleVault($alice, $alpha->id);
    DB::table('favorite_vaults')->insert(['user_id' => $alice->id, 'vault_id' => $hidden->id]);

    expect($favorites->favoriteVaults($alice)->pluck('name')->all())->toBe(['Alpha', 'Beta']);
});

test('favorite entries are filtered to visible live entries and sorted by title', function () {
    ['admin' => $admin, 'alice' => $alice, 'bob' => $bob, 'vault' => $vault] = vaultigen_entry_fixture();
    $bravo = vaultigen_make_entry($alice, $vault, ['title' => 'Bravo']);
    $alpha = vaultigen_make_entry($alice, $vault, ['title' => 'Alpha']);
    $trashed = vaultigen_make_entry($alice, $vault, ['title' => 'Trashed']);
    $hiddenVault = vaultigen_make_vault($admin, $bob, ['name' => 'Hidden']);
    $hiddenEntry = vaultigen_make_entry($bob, $hiddenVault, ['title' => 'Hidden entry']);
    $favorites = app(Favorites::class);

    $favorites->toggleEntry($alice, $bravo->id);
    $favorites->toggleEntry($alice, $alpha->id);
    DB::table('favorite_entries')->insert([
        ['user_id' => $alice->id, 'entry_id' => $trashed->id],
        ['user_id' => $alice->id, 'entry_id' => $hiddenEntry->id],
    ]);
    DB::table('entries')->where('id', $trashed->id)->update(['deleted_at' => now()]);

    expect($favorites->favoriteEntries($alice)->pluck('title')->all())->toBe(['Alpha', 'Bravo']);
});

test('favorite vault navigation items link to the vault and track the active page', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    app(Favorites::class)->toggleVault($alice, $vault->id);

    $activeRequest = Request::create(VaultEntries::getUrl(['vault' => $vault->id]), 'GET');
    $activeRequest->setRouteResolver(fn () => app('router')->getRoutes()->match($activeRequest));
    app()->instance('originalRequest', $activeRequest);

    $items = FavoriteVaultNavigation::items($alice);

    expect($items)->toHaveCount(1)
        ->and($items[0]->getLabel())->toBe($vault->name)
        ->and($items[0]->getUrl())->toBe(VaultEntries::getUrl(['vault' => $vault->id]))
        ->and($items[0]->getSort())->toBe(2)
        ->and($items[0]->isActive())->toBeTrue();

    $inactiveRequest = Request::create(MyVaults::getUrl(), 'GET');
    $inactiveRequest->setRouteResolver(fn () => app('router')->getRoutes()->match($inactiveRequest));
    app()->instance('originalRequest', $inactiveRequest);

    expect(FavoriteVaultNavigation::items($alice)[0]->isActive())->toBeFalse();
});

test('the my vaults navigation item carries one shortcut per favorite vault', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    app(Favorites::class)->toggleVault($alice, $vault->id);
    $this->actingAs($alice);

    $labels = array_map(fn (NavigationItem $item): string => $item->getLabel(), MyVaults::getNavigationItems());

    expect($labels)->toBe(['My vaults', $vault->name]);

    $outsider = User::factory()->withTwoFactor()->create();
    $this->actingAs($outsider);
    expect(MyVaults::getNavigationItems())->toHaveCount(1);
});

test('users toggle a favorite vault from the my vaults table', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $this->actingAs($alice);

    Livewire::test(MyVaults::class)
        ->callTableAction('favorite', $vault)
        ->assertNotified('Vault added to favorites')
        ->assertDispatched('refresh-sidebar');

    $this->assertDatabaseHas('favorite_vaults', ['user_id' => $alice->id, 'vault_id' => $vault->id]);

    Livewire::test(MyVaults::class)
        ->callTableAction('favorite', $vault)
        ->assertNotified('Vault removed from favorites');

    $this->assertDatabaseMissing('favorite_vaults', ['user_id' => $alice->id, 'vault_id' => $vault->id]);
});

test('users toggle favorites from the vault page header and entry table', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $this->actingAs($alice);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->callAction('favorite')
        ->assertNotified('Vault added to favorites')
        ->assertDispatched('refresh-sidebar');
    $this->assertDatabaseHas('favorite_vaults', ['user_id' => $alice->id, 'vault_id' => $vault->id]);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->callTableAction('favorite', $entry)
        ->assertNotified('Entry added to favorites');
    $this->assertDatabaseHas('favorite_entries', ['user_id' => $alice->id, 'entry_id' => $entry->id]);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->callTableAction('favorite', $entry)
        ->assertNotified('Entry removed from favorites');
    $this->assertDatabaseMissing('favorite_entries', ['user_id' => $alice->id, 'entry_id' => $entry->id]);
});

test('users toggle a favorite entry from the entry detail header', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $this->actingAs($alice);

    Livewire::test(EntryDetail::class, ['entryId' => $entry->id])
        ->callAction('favorite')
        ->assertNotified('Entry added to favorites');
    $this->assertDatabaseHas('favorite_entries', ['user_id' => $alice->id, 'entry_id' => $entry->id]);

    Livewire::test(EntryDetail::class, ['entryId' => $entry->id])
        ->callAction('favorite')
        ->assertNotified('Entry removed from favorites');
    $this->assertDatabaseMissing('favorite_entries', ['user_id' => $alice->id, 'entry_id' => $entry->id]);
});

test('the dashboard shows favorite sections only when something is favorited', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault, ['title' => 'Dashboard favorite']);
    $this->actingAs($alice);

    $this->get(Dashboard::getUrl())->assertOk()
        ->assertDontSee('Favorite vaults')
        ->assertDontSee('Favorite entries');

    app(Favorites::class)->toggleVault($alice, $vault->id);
    app(Favorites::class)->toggleEntry($alice, $entry->id);

    $this->get(Dashboard::getUrl())->assertOk()
        ->assertSee('Favorite vaults')
        ->assertSee('Favorite entries')
        ->assertSee('Dashboard favorite')
        ->assertSee('copySecret', false)
        ->assertSee((string) Js::from(route('entries.reveal', ['entry' => $entry->id, 'field' => 'password'])), false)
        ->assertSee('<a href="https://example.com" target="_blank" rel="noopener noreferrer"', false);
});

test('favorite vault shortcuts appear in the sidebar and mark the open vault active', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    app(Favorites::class)->toggleVault($alice, $vault->id);
    $this->actingAs($alice);

    $this->get(VaultEntries::getUrl(['vault' => $vault->id]))
        ->assertOk()
        ->assertSee(VaultEntries::getUrl(['vault' => $vault->id]), false)
        ->assertSeeHtml('aria-current="page"');
});

test('another user never sees a favorite vault shortcut', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    app(Favorites::class)->toggleVault($alice, $vault->id);

    $this->actingAs(User::factory()->withTwoFactor()->create());
    $this->get(MyVaults::getUrl())->assertOk()->assertDontSee($vault->name);
});
