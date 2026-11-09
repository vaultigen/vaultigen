<?php

use App\Filament\Pages\EntryForm;
use App\Filament\Pages\VaultEntries;
use App\Models\Entry;
use App\Services\EntrySearch;
use App\Services\EntryVault;
use App\Services\Normalizer;
use App\Services\ScopedGrants;
use App\Services\VaultAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('normalization trims, collapses whitespace, lowercases and bounds search terms', function () {
    $normalizer = app(Normalizer::class);

    expect($normalizer->normalize("  Hello\t  World  "))->toBe('hello world')
        ->and($normalizer->normalize('ÄÖÜ'))->toBe('äöü')
        ->and($normalizer->escapeLike('50%_off!'))->toBe('50!%!_off!!')
        ->and($normalizer->containsPattern(str_repeat('a', 150)))->toBe('%'.str_repeat('a', Normalizer::MAX_SEARCH_TERM).'%')
        ->and($normalizer->containsPattern('   '))->toBe('%%');
});

test('search matches only the exposed metadata fields and escapes wildcards', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    $tag = vaultigen_make_tag($alice, $vault, 'Production');
    $target = Entry::query()->where('title', 'Folder entry')->sole();
    app(EntryVault::class)->update($alice, $target->id, vaultigen_entry_update($alice, [
        'title' => 'Folder entry',
        'folder_id' => $folderA->id,
        'tags' => [$tag->id],
    ]), $target->lock_version);
    vaultigen_make_entry($alice, $vault, ['title' => '100% literal', 'username' => 'HiddenUser-Zx9']);

    $search = function (string $term) use ($alice, $vault): array {
        return app(EntrySearch::class)
            ->apply(app(EntryVault::class)->query($alice, $vault->id), $term)
            ->pluck('title')->sort()->values()->all();
    };

    expect($search('folder'))->toBe(['Folder entry'])
        ->and($search('production'))->toBe(['Folder entry'])
        ->and($search('example.com'))->toBe(['100% literal', 'Folder entry', 'Root entry'])
        ->and($search('login'))->toBe(['100% literal', 'Folder entry', 'Root entry'])
        ->and($search('%'))->toBe(['100% literal'])
        ->and($search('_'))->toBe([])
        ->and($search('HiddenUser'))->toBe([])
        ->and(app(EntrySearch::class)->apply(app(EntryVault::class)->query($alice, $vault->id), '  ')->count())->toBe(3);

    expect(app(EntrySearch::class)->apply(app(EntryVault::class)->query($bob, $vault->id), 'root')->pluck('title')->all())->toBe([]);
});

test('entries carry folder and tag placement with validation', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault, 'folderA' => $folderA, 'folderB' => $folderB] = vaultigen_scope_fixture();
    $tag = vaultigen_make_tag($alice, $vault, 'Production', 'success');

    $entry = app(EntryVault::class)->create($alice, $vault->id, array_merge(vaultigen_entry_update($alice), [
        'title' => 'Placed',
        'password' => 'PlacedSecret-Zx9',
        'folder_id' => $folderA->id,
        'tags' => [$tag->id],
    ]));
    $placed = Entry::query()->findOrFail($entry);

    expect($placed->folder_id)->toBe($folderA->id)
        ->and($placed->tags->pluck('id')->all())->toBe([$tag->id])
        ->and(DB::table('entry_tag')->where('entry_id', $entry)->count())->toBe(1);

    expect(fn () => app(EntryVault::class)->create($bob, $vault->id, array_merge(vaultigen_entry_update($bob), [
        'title' => 'Root denied', 'password' => 'x', 'folder_id' => null,
    ])))->toThrow(AuthorizationException::class);

    expect(fn () => app(EntryVault::class)->create($bob, $vault->id, array_merge(vaultigen_entry_update($bob), [
        'title' => 'Other folder', 'password' => 'x', 'folder_id' => $folderB->id,
    ])))->toThrow(AuthorizationException::class);
});

test('a manager may create a folder inline; a folder editor may not', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    app(ScopedGrants::class)->grantOnFolder($alice, $folderA->id, $carol->principal_id, 'editor');

    $entry = app(EntryVault::class)->create($alice, $vault->id, array_merge(vaultigen_entry_update($alice), [
        'title' => 'Inline folder', 'password' => 'x', 'new_folder_name' => 'Compliance',
    ]));
    $folder = DB::table('folders')->where('normalized_name', 'compliance')->sole();
    expect(Entry::query()->findOrFail($entry)->folder_id)->toBe($folder->id);

    expect(fn () => app(EntryVault::class)->create($carol, $vault->id, array_merge(vaultigen_entry_update($carol), [
        'title' => 'Nope', 'password' => 'x', 'new_folder_name' => 'Sneaky',
    ])))->toThrow(AuthorizationException::class);

    expect(fn () => app(EntryVault::class)->create($bob, $vault->id, array_merge(vaultigen_entry_update($bob), [
        'title' => 'Sneaky', 'password' => 'x', 'new_folder_name' => 'Sneaky',
    ])))->toThrow(AuthorizationException::class);
});

test('a folder editor keeps tag placement when the form omits it', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    $tag = vaultigen_make_tag($alice, $vault, 'Production');
    $entryId = app(EntryVault::class)->create($alice, $vault->id, array_merge(vaultigen_entry_update($alice), [
        'title' => 'Placed', 'password' => 'PlacedSecret-Zx9', 'folder_id' => $folderA->id,
        'tags' => [$tag->id],
    ]));

    app(EntryVault::class)->update($bob, $entryId, [
        'title' => 'Placed by Bob',
        'owner_user_id' => $alice->id,
        'expires_at' => null,
        'generated' => false,
        'username' => null,
        'password' => null,
        'url' => null,
        'notes' => null,
        'folder_id' => $folderA->id,
    ], 1);

    $entry = Entry::query()->findOrFail($entryId);
    expect($entry->title)->toBe('Placed by Bob')
        ->and($entry->tags->pluck('id')->all())->toBe([$tag->id]);
});

test('restoring a revision reapplies the recorded placement', function () {
    ['alice' => $alice, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    $tag = vaultigen_make_tag($alice, $vault, 'Production');
    $entryId = app(EntryVault::class)->create($alice, $vault->id, array_merge(vaultigen_entry_update($alice), [
        'title' => 'Placed', 'password' => 'PlacedSecret-Zx9', 'folder_id' => $folderA->id,
        'tags' => [$tag->id],
    ]));

    app(EntryVault::class)->update($alice, $entryId, array_merge(vaultigen_entry_update($alice), [
        'title' => 'Moved out', 'folder_id' => null, 'tags' => [],
    ]), 1);
    $revision = DB::table('entry_revisions')->where('entry_id', $entryId)->where('revision_number', 1)->sole();
    app(EntryVault::class)->restore($alice, $entryId, $revision->id);

    $entry = Entry::query()->findOrFail($entryId);
    expect($entry->title)->toBe('Placed')
        ->and($entry->folder_id)->toBe($folderA->id)
        ->and($entry->tags->pluck('id')->all())->toBe([$tag->id]);
});

test('the entry form scopes placement choices to the actor', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    vaultigen_make_tag($alice, $vault, 'Production');
    app(ScopedGrants::class)->grantOnFolder($alice, $folderA->id, $carol->principal_id, 'editor');

    $this->actingAs($alice);
    Livewire::test(EntryForm::class, ['vaultId' => $vault->id])
        ->assertViewHas('vaultScope', true)
        ->assertViewHas('canCreateFolder', true)
        ->assertViewHas('canPlaceAtRoot', true)
        ->assertSee('No folder (vault root)')
        ->assertSee('Production')
        ->assertSeeHtml('name="tags[]"')
        ->assertSeeHtml('name="type"')
        ->assertSeeHtml('name="owner_user_id"')
        ->assertSeeHtml('name="folder_id"')
        ->assertSeeHtml('name="new_folder_name"')
        ->assertSeeHtml('name="expires_at"')
        ->assertSeeHtml('name="generated"')
        ->assertSeeHtml('name="algorithm"')
        ->assertSeeHtml('name="digits"')
        ->assertSeeHtml('name="period"');

    $this->actingAs($carol);
    Livewire::test(EntryForm::class, ['vaultId' => $vault->id])
        ->assertViewHas('vaultScope', false)
        ->assertViewHas('canCreateFolder', false)
        ->assertViewHas('canPlaceAtRoot', false)
        ->assertViewHas('tagSuggestions', []);

    $this->actingAs($bob);
    Livewire::test(EntryForm::class, ['vaultId' => $vault->id, 'folderId' => $folderA->id])
        ->assertViewHas('selectedFolderId', $folderA->id)
        ->assertViewHas('canCreateFolder', false);
});

test('the vault entries table groups by folder and filters by folder and tag', function () {
    ['alice' => $alice, 'vault' => $vault, 'folderA' => $folderA, 'rootEntry' => $rootEntry, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $empty = vaultigen_make_folder($alice, $vault, 'Empty folder');
    $tag = vaultigen_make_tag($alice, $vault, 'Production');
    app(EntryVault::class)->update($alice, $entryA->id, vaultigen_entry_update($alice, [
        'title' => 'Folder entry', 'folder_id' => $folderA->id, 'tags' => [$tag->id],
    ]), 2);
    $this->actingAs($alice);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->assertSee('No folder')
        ->assertSee('Operations')
        ->searchTable('production')
        ->assertCanSeeTableRecords([$entryA])
        ->assertCanNotSeeTableRecords([$rootEntry]);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->filterTable('folder', '__root__')
        ->assertCanSeeTableRecords([$rootEntry])
        ->assertCanNotSeeTableRecords([$entryA]);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->filterTable('folder', $folderA->id)
        ->assertCanSeeTableRecords([$entryA]);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->filterTable('tag', $tag->id)
        ->assertCanSeeTableRecords([$entryA]);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->assertTableFilterExists('folder')
        ->assertTableFilterExists('tag')
        ->assertSee('Operations');
    expect($empty->name)->toBe('Empty folder');
});

test('a folder-only user sees only their folder and a vault holder sees the root group', function () {
    ['alice' => $alice, 'carol' => $carol, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    app(ScopedGrants::class)->grantOnFolder($alice, $folderA->id, $carol->principal_id, 'editor');
    expect(app(VaultAccess::class)->hasVaultAccess($carol, $vault->id))->toBeTrue();
    expect(app(VaultAccess::class)->visibleVaults($carol)->find($vault->id))->not->toBeNull();
    $this->actingAs($carol);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->assertSee('Operations')
        ->assertDontSee('No folder');

    $this->actingAs($alice);
    Livewire::test(VaultEntries::class, ['vault' => $vault->id])->assertSee('No folder');
});

test('an editor cannot move an entry into a folder they cannot reach', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault, 'folderA' => $folderA, 'folderB' => $folderB, 'entryA' => $entryA] = vaultigen_scope_fixture();

    expect(fn () => app(EntryVault::class)->update($bob, $entryA->id, vaultigen_entry_update($alice, [
        'folder_id' => $folderB->id,
    ]), $entryA->lock_version))->toThrow(AuthorizationException::class);
});

test('the entries table supports descending folder grouping', function () {
    ['alice' => $alice, 'vault' => $vault, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $this->actingAs($alice);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->set('tableGrouping', 'folder.name:desc')
        ->assertOk()
        ->assertCanSeeTableRecords([$entryA]);
});

test('restoring a revision does not move the entry into a folder the actor may not use', function () {
    ['alice' => $alice, 'dave' => $dave, 'vault' => $vault, 'folderA' => $folderA, 'folderB' => $folderB] = vaultigen_scope_fixture();
    $entryId = app(EntryVault::class)->create($alice, $vault->id, array_merge(vaultigen_entry_update($alice), [
        'title' => 'Original', 'password' => 'PlacedSecret-Zx9', 'folder_id' => $folderB->id,
    ]));
    app(EntryVault::class)->update($alice, $entryId, array_merge(vaultigen_entry_update($alice), [
        'title' => 'Changed', 'folder_id' => $folderA->id,
    ]), 1);
    app(ScopedGrants::class)->grantOnEntry($alice, $entryId, $dave->principal_id, 'manager');
    $revision = DB::table('entry_revisions')->where('entry_id', $entryId)->where('revision_number', 1)->sole();

    app(EntryVault::class)->restore($dave, $entryId, $revision->id);

    $entry = Entry::query()->findOrFail($entryId);
    expect($entry->title)->toBe('Original')
        ->and($entry->folder_id)->toBe($folderA->id);
});

test('restoring a revision does not reinstate an owner who may no longer own entries', function () {
    ['alice' => $alice, 'dave' => $dave, 'vault' => $vault] = vaultigen_scope_fixture();
    $entry = vaultigen_make_entry($alice, $vault, ['title' => 'Owned']);
    DB::table('entries')->where('id', $entry->id)->update(['owner_user_id' => $dave->id]);
    app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice, ['title' => 'Changed']), 1);
    $revision = DB::table('entry_revisions')->where('entry_id', $entry->id)->sole();

    app(EntryVault::class)->restore($alice, $entry->id, $revision->id);

    $entry = Entry::query()->findOrFail($entry->id);
    expect($entry->title)->toBe('Owned')
        ->and($entry->owner_user_id)->toBe($alice->id);
});
