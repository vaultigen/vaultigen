<?php

use App\Filament\Pages\EntryDetail;
use App\Filament\Pages\EntryMove;
use App\Models\Entry;
use App\Services\EntryVault;
use App\Services\ScopedGrants;
use App\Services\VaultAccess;
use App\Services\VaultAdministration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('an editor moves an entry between folders in the same vault', function () {
    ['alice' => $alice, 'vault' => $vault, 'folderA' => $folderA, 'folderB' => $folderB, 'entryA' => $entryA] = vaultigen_scope_fixture();

    app(EntryVault::class)->move($alice, $entryA->id, $vault->id, $folderB->id);

    expect($entryA->fresh()->folder_id)->toBe($folderB->id)
        ->and(DB::table('entry_revisions')->where('entry_id', $entryA->id)->count())->toBe(2);
    $this->assertDatabaseHas('audit_events', ['action' => 'entry.moved']);
});

test('moving into an unauthorized destination is refused', function () {
    ['alice' => $alice, 'carol' => $carol, 'vault' => $vault, 'folderA' => $folderA, 'folderB' => $folderB, 'entryA' => $entryA] = vaultigen_scope_fixture();
    app(ScopedGrants::class)->grantOnFolder($alice, $folderA->id, $carol->principal_id, 'editor');

    expect(fn () => app(EntryVault::class)->move($carol, $entryA->id, $vault->id, $folderB->id))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(EntryVault::class)->move($alice, $entryA->id, $vault->id, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
});

test('a cross-vault move re-encrypts the entry and its history and drops vault labels', function () {
    ['admin' => $admin, 'alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault, 'folderA' => $folderA, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $tag = vaultigen_make_tag($alice, $vault, 'Production');
    app(EntryVault::class)->update($alice, $entryA->id, vaultigen_entry_update($alice, [
        'title' => 'Folder entry', 'folder_id' => $folderA->id, 'tags' => [$tag->id],
    ]), $entryA->lock_version);
    $entryA = Entry::query()->findOrFail($entryA->id);
    $second = vaultigen_make_vault($admin, $alice, ['name' => 'Destination']);
    $secondFolder = vaultigen_make_folder($alice, $second, 'Import');

    app(EntryVault::class)->move($alice, $entryA->id, $second->id, $secondFolder->id);

    $moved = Entry::query()->findOrFail($entryA->id);
    expect($moved->vault_id)->toBe($second->id)
        ->and($moved->folder_id)->toBe($secondFolder->id)
        ->and($moved->owner_user_id)->toBe($alice->id)
        ->and($moved->tags()->count())->toBe(0)
        ->and(DB::table('entry_grants')->where('entry_id', $entryA->id)->count())->toBe(0)
        ->and(app(EntryVault::class)->reveal($alice, $entryA->id, 'password'))->toBe('correct-horse-battery-staple-42');

    // The old revisions are preserved under the destination key, plus a pre-move snapshot.
    expect(DB::table('entry_revisions')->where('entry_id', $entryA->id)->count())->toBe(3);

    expect(app(VaultAccess::class)->hasVaultAccess($carol, $second->id))->toBeFalse();
    expect(fn () => app(EntryVault::class)->find($carol, $entryA->id))
        ->toThrow(NotFoundHttpException::class);

    app(ScopedGrants::class)->grantOnEntry($alice, $entryA->id, $carol->principal_id, 'viewer');
    expect(app(EntryVault::class)->find($carol->refresh(), $entryA->id))->toBeInstanceOf(Entry::class);
});

test('cross-vault move can create a destination folder and refuses inactive destinations', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $second = vaultigen_make_vault($admin, $alice, ['name' => 'Destination']);

    app(EntryVault::class)->move($alice, $entryA->id, $second->id, null, 'Archive');
    $folder = DB::table('folders')->where('vault_id', $second->id)->where('normalized_name', 'archive')->sole();
    expect(Entry::query()->findOrFail($entryA->id)->folder_id)->toBe($folder->id);

    $readOnly = vaultigen_make_vault($admin, $alice, ['name' => 'Read only']);
    app(VaultAdministration::class)->setState($admin, $readOnly->id, 'read_only');
    $original = vaultigen_make_entry($alice, $vault);

    expect(fn () => app(EntryVault::class)->move($alice, $original->id, $readOnly->id, null))
        ->toThrow(ValidationException::class);
});

test('the move page renders authorized destinations and performs the move', function () {
    ['admin' => $admin, 'alice' => $alice, 'carol' => $carol, 'vault' => $vault, 'folderB' => $folderB, 'entryA' => $entryA] = vaultigen_scope_fixture();
    vaultigen_make_vault($admin, $alice, ['name' => 'Destination']);
    $this->actingAs($alice);

    Livewire::test(EntryMove::class, ['entryId' => $entryA->id])
        ->assertSee('Destination')
        ->assertSee('Access impact')
        ->set('data.destination_vault_id', $vault->id)
        ->set('data.destination_folder_id', $folderB->id)
        ->call('move')
        ->assertNotified('Entry moved');

    expect($entryA->fresh()->folder_id)->toBe($folderB->id);

    $this->actingAs($carol);
    Livewire::test(EntryMove::class, ['entryId' => $entryA->id])->assertNotFound();
    Livewire::test(EntryMove::class, ['entryId' => ''])->assertNotFound();
});

test('the move page rejects an invalid destination without changing data', function () {
    ['alice' => $alice, 'vault' => $vault, 'folderA' => $folderA, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $this->actingAs($alice);

    Livewire::test(EntryMove::class, ['entryId' => $entryA->id])
        ->set('data.destination_vault_id', $vault->id)
        ->set('data.destination_folder_id', (string) Str::uuid())
        ->call('move')
        ->assertHasFormErrors(['destination_folder_id']);

    expect($entryA->fresh()->folder_id)->toBe($folderA->id);
});

test('the entry detail page offers move and additional access to managers', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault, 'entryA' => $entryA] = vaultigen_scope_fixture();

    $this->actingAs($alice);
    $this->get(EntryDetail::getUrl(['entry' => $entryA->id]))
        ->assertOk()
        ->assertSee('Move')
        ->assertSee('Additional access');

    $this->actingAs($bob);
    $this->get(EntryDetail::getUrl(['entry' => $entryA->id]))->assertOk()->assertSee($entryA->title);

    $this->actingAs($carol);
    $this->get(EntryDetail::getUrl(['entry' => $entryA->id]))->assertOk()->assertSee($entryA->title);
});

test('a viewer cannot move an entry', function () {
    ['carol' => $carol, 'vault' => $vault, 'folderB' => $folderB, 'entryA' => $entryA] = vaultigen_scope_fixture();

    expect(fn () => app(EntryVault::class)->move($carol, $entryA->id, $vault->id, $folderB->id))
        ->toThrow(AuthorizationException::class);
});

test('restoring a moved revision falls back when the old placement is gone', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault, 'folderA' => $folderA, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $tag = vaultigen_make_tag($alice, $vault, 'Production');
    app(EntryVault::class)->update($alice, $entryA->id, vaultigen_entry_update($alice, [
        'title' => 'Folder entry', 'folder_id' => $folderA->id, 'tags' => [$tag->id],
    ]), $entryA->lock_version);
    $second = vaultigen_make_vault($admin, $alice, ['name' => 'Destination']);

    app(EntryVault::class)->move($alice, $entryA->id, $second->id, null);

    $revision = DB::table('entry_revisions')->where('entry_id', $entryA->id)->orderByDesc('revision_number')->first();
    app(EntryVault::class)->restore($alice, $entryA->id, (string) $revision->id);

    $entry = Entry::query()->findOrFail($entryA->id);
    expect($entry->folder_id)->toBeNull()
        ->and($entry->tags()->count())->toBe(0);
});

test('the move page renders with no destination selected', function () {
    ['alice' => $alice, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $this->actingAs($alice);

    Livewire::test(EntryMove::class, ['entryId' => $entryA->id])
        ->set('data.destination_vault_id', '')
        ->assertOk()
        ->assertSee('Destination');
});

test('an editor cannot carry restricted history into another vault', function () {
    ['admin' => $admin, 'bob' => $bob, 'vault' => $vault, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $own = vaultigen_make_vault($admin, $bob, ['name' => 'Personal']);

    expect(fn () => app(EntryVault::class)->move($bob, $entryA->id, $own->id, null))
        ->toThrow(AuthorizationException::class, __('entries.errors.move_exposes_history'));

    expect($entryA->fresh()->vault_id)->toBe($vault->id);
});

test('an editor may move an entry without history to another vault', function () {
    ['admin' => $admin, 'alice' => $alice, 'bob' => $bob, 'folderA' => $folderA, 'vault' => $vault] = vaultigen_scope_fixture();
    $entry = vaultigen_make_entry($alice, $vault, ['folder_id' => $folderA->id]);
    $own = vaultigen_make_vault($admin, $bob, ['name' => 'Personal']);

    app(EntryVault::class)->move($bob, $entry->id, $own->id, null);

    expect($entry->fresh()->vault_id)->toBe($own->id);
});

test('a folder change cannot make an editor a manager of restricted history', function (Closure $changeFolder) {
    ['alice' => $alice, 'bob' => $bob, 'folderA' => $folderA, 'folderB' => $folderB, 'entryA' => $entryA] = vaultigen_scope_fixture();
    app(ScopedGrants::class)->grantOnFolder($alice, $folderB->id, $bob->principal_id, 'manager');

    expect(fn () => $changeFolder($bob, $entryA, $folderB->id))
        ->toThrow(AuthorizationException::class, __('entries.errors.move_exposes_history'));

    expect($entryA->fresh()->folder_id)->toBe($folderA->id);
})->with([
    'move' => fn ($actor, $entry, $folderId) => app(EntryVault::class)->move($actor, $entry->id, $entry->vault_id, $folderId),
    'update' => fn ($actor, $entry, $folderId) => app(EntryVault::class)->update($actor, $entry->id, vaultigen_entry_update($entry->owner, ['title' => $entry->title, 'folder_id' => $folderId]), $entry->lock_version),
]);

test('an editor still moves an entry between folders without gaining history', function () {
    ['alice' => $alice, 'bob' => $bob, 'folderB' => $folderB, 'vault' => $vault, 'entryA' => $entryA] = vaultigen_scope_fixture();
    app(ScopedGrants::class)->grantOnFolder($alice, $folderB->id, $bob->principal_id, 'editor');

    app(EntryVault::class)->move($bob, $entryA->id, $vault->id, $folderB->id);

    expect($entryA->fresh()->folder_id)->toBe($folderB->id);
});

test('the move page reports a refused move instead of failing', function () {
    ['admin' => $admin, 'bob' => $bob, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $own = vaultigen_make_vault($admin, $bob, ['name' => 'Personal']);

    Livewire::actingAs($bob)
        ->withQueryParams(['entry' => $entryA->id])
        ->test(EntryMove::class)
        ->set('data.destination_vault_id', $own->id)
        ->set('data.destination_folder_id', '')
        ->call('move')
        ->assertNotified((string) __('entries.move.not_moved'));
});
