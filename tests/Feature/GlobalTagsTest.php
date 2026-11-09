<?php

use App\Filament\Pages\GlobalTags;
use App\Filament\Pages\ManageTags;
use App\Models\Entry;
use App\Models\Tag;
use App\Services\EntryVault;
use App\Services\ScopedGrants;
use App\Services\VaultDirectory;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('global tags are managed only by super admins', function () {
    ['admin' => $admin, 'alice' => $alice] = vaultigen_entry_fixture();
    $tag = vaultigen_make_global_tag($admin, 'Compliance', 'info');

    expect($tag->scope)->toBe(Tag::SCOPE_GLOBAL)
        ->and($tag->vault_id)->toBeNull()
        ->and($tag->global_key)->toBe('compliance');

    expect(fn () => vaultigen_make_global_tag($alice, 'Nope'))->toThrow(AuthorizationException::class);
    expect(fn () => app(VaultDirectory::class)->updateTag($alice, $tag->id, ['name' => 'Nope']))->toThrow(AuthorizationException::class);
    expect(fn () => app(VaultDirectory::class)->deleteTag($alice, $tag->id))->toThrow(AuthorizationException::class);

    app(VaultDirectory::class)->updateTag($admin, $tag->id, ['name' => 'Compliance v2', 'color' => 'warning']);
    expect($tag->fresh()->name)->toBe('Compliance v2');
});

test('global tag names are unique and local tags cannot shadow them', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    vaultigen_make_global_tag($admin, 'Compliance');

    expect(fn () => vaultigen_make_global_tag($admin, 'compliance'))->toThrow(ValidationException::class);
    expect(fn () => vaultigen_make_tag($alice, $vault, 'Compliance'))->toThrow(ValidationException::class);
});

test('global tags are offered in every vault and attach to entries', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_scope_fixture();
    $global = vaultigen_make_global_tag($admin, 'Compliance');
    $local = vaultigen_make_tag($alice, $vault, 'Production');

    $entryId = app(EntryVault::class)->create($alice, $vault->id, array_merge(vaultigen_entry_update($alice), [
        'title' => 'Global tagged', 'password' => 'Secret-Zx9', 'tags' => [$global->id, $local->id],
    ]));

    expect(Entry::query()->findOrFail($entryId)->tags->pluck('id')->sort()->values()->all())
        ->toBe(collect([$global->id, $local->id])->sort()->values()->all());

    $options = app(VaultDirectory::class)->tagOptions($alice, $vault->id);
    expect($options)->toHaveKeys([$global->id, $local->id])
        ->and($options[$global->id])->toBe('Compliance (global)');

    app(VaultDirectory::class)->validateTags($vault->id, [$global->id]);
    expect(fn () => app(VaultDirectory::class)->validateTags($vault->id, ['not-a-real-tag']))
        ->toThrow(ValidationException::class);
});

test('restoring a revision reapplies a global tag', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_scope_fixture();
    $global = vaultigen_make_global_tag($admin, 'Compliance');
    $entryId = app(EntryVault::class)->create($alice, $vault->id, array_merge(vaultigen_entry_update($alice), [
        'title' => 'Tagged', 'password' => 'Secret-Zx9', 'tags' => [$global->id],
    ]));

    app(EntryVault::class)->update($alice, $entryId, array_merge(vaultigen_entry_update($alice), [
        'title' => 'Untagged', 'tags' => [],
    ]), 1);
    $revision = DB::table('entry_revisions')->where('entry_id', $entryId)->where('revision_number', 1)->sole();
    app(EntryVault::class)->restore($alice, $entryId, (string) $revision->id);

    expect(Entry::query()->findOrFail($entryId)->tags->pluck('id')->all())->toBe([$global->id]);
});

test('deleting a tag reports its usage and detaches it from entries', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $tag = vaultigen_make_global_tag($admin, 'Compliance');
    $entry = vaultigen_make_entry($alice, $vault, ['title' => 'Tagged', 'tags' => [$tag->id]]);

    expect(app(VaultDirectory::class)->usage($tag->id))
        ->toBe(['entries' => 1, 'vaults' => [['id' => $vault->id, 'name' => $vault->name]]]);

    app(VaultDirectory::class)->deleteTag($admin, $tag->id);

    expect(Tag::query()->find($tag->id))->toBeNull()
        ->and(DB::table('entry_tag')->where('tag_id', $tag->id)->count())->toBe(0)
        ->and(Entry::query()->findOrFail($entry->id)->tags()->count())->toBe(0);
});

test('a vault editor deletes local tags but never global tags', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $local = vaultigen_make_tag($alice, $vault, 'Production');
    app(VaultDirectory::class)->deleteTag($alice, $local->id);
    expect(Tag::query()->find($local->id))->toBeNull();

    $global = vaultigen_make_global_tag($admin, 'Compliance');
    expect(fn () => app(VaultDirectory::class)->deleteTag($alice, $global->id))->toThrow(AuthorizationException::class);
});

test('a cross-vault move keeps global tags and drops local tags', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault, 'folderA' => $folderA, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $global = vaultigen_make_global_tag($admin, 'Compliance');
    $local = vaultigen_make_tag($alice, $vault, 'Production');
    app(EntryVault::class)->update($alice, $entryA->id, vaultigen_entry_update($alice, [
        'title' => 'Folder entry', 'folder_id' => $folderA->id, 'tags' => [$global->id, $local->id],
    ]), $entryA->lock_version);
    $second = vaultigen_make_vault($admin, $alice, ['name' => 'Destination']);

    app(EntryVault::class)->move($alice, $entryA->id, $second->id, null);

    $moved = Entry::query()->findOrFail($entryA->id);
    expect($moved->vault_id)->toBe($second->id)
        ->and($moved->tags->pluck('id')->all())->toBe([$global->id]);
});

test('the global tags page is admin only and manages tags', function () {
    ['admin' => $admin, 'alice' => $alice] = vaultigen_entry_fixture();

    $this->actingAs($alice)->get(GlobalTags::getUrl())->assertForbidden();

    $this->actingAs($admin)->get(GlobalTags::getUrl())->assertOk()->assertSee('Global tags are available to every vault');
    Livewire::test(GlobalTags::class)
        ->assertSee('Global tags are available to every vault')
        ->callAction('create', ['name' => 'Compliance', 'color' => 'info'])
        ->assertNotified('Global tag created');

    $tag = Tag::query()->where('scope', Tag::SCOPE_GLOBAL)->where('normalized_name', 'compliance')->sole();

    Livewire::test(GlobalTags::class)
        ->callAction(TestAction::make('edit')->table($tag), ['name' => 'Compliance v2', 'color' => 'warning'])
        ->assertNotified('Global tag updated');
    expect($tag->fresh()->name)->toBe('Compliance v2');

    Livewire::test(GlobalTags::class)
        ->callAction(TestAction::make('delete')->table($tag))
        ->assertNotified('Global tag deleted');
    expect(Tag::query()->find($tag->id))->toBeNull();
});

test('the global tags page surfaces usage warnings and validation failures', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $used = vaultigen_make_global_tag($admin, 'Compliance');
    vaultigen_make_entry($alice, $vault, ['title' => 'Tagged', 'tags' => [$used->id]]);
    $other = vaultigen_make_global_tag($admin, 'Retired');
    $this->actingAs($admin);

    Livewire::test(GlobalTags::class)
        ->callAction('create', ['name' => 'Compliance'])
        ->assertNotified('Global tag not created');

    Livewire::test(GlobalTags::class)
        ->callAction(TestAction::make('edit')->table($other), ['name' => 'Compliance'])
        ->assertNotified('Global tag not updated');

    Livewire::test(GlobalTags::class)
        ->callTableAction('delete', $used)
        ->assertNotified('Global tag deleted');
});

test('the global tags table filters by usage and vault and sorts by counts', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $used = vaultigen_make_global_tag($admin, 'Compliance');
    $unused = vaultigen_make_global_tag($admin, 'Retired');
    vaultigen_make_entry($alice, $vault, ['title' => 'Tagged', 'tags' => [$used->id]]);
    $this->actingAs($admin);

    Livewire::test(GlobalTags::class)
        ->assertTableFilterExists('assigned')
        ->assertTableFilterExists('vault')
        ->assertCanSeeTableRecords([$used, $unused])
        ->filterTable('assigned', true)
        ->assertCanSeeTableRecords([$used])
        ->assertCanNotSeeTableRecords([$unused]);

    Livewire::test(GlobalTags::class)
        ->filterTable('assigned', false)
        ->assertCanSeeTableRecords([$unused])
        ->assertCanNotSeeTableRecords([$used]);

    Livewire::test(GlobalTags::class)
        ->filterTable('vault', $vault->id)
        ->assertCanSeeTableRecords([$used])
        ->assertCanNotSeeTableRecords([$unused]);

    Livewire::test(GlobalTags::class)
        ->sortTable('entries_count')
        ->sortTable('vaults_count', 'desc')
        ->assertOk();
});

test('entry tag input resolves names, reuses tags, and creates local tags', function () {
    ['admin' => $admin, 'alice' => $alice, 'bob' => $bob, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    $global = vaultigen_make_global_tag($admin, 'Compliance');
    $local = vaultigen_make_tag($alice, $vault, 'Production');
    $directory = app(VaultDirectory::class);

    $ids = $directory->resolveTagInput($alice, $vault->id, ['Compliance', 'production', 'New Tag', $global->id, '']);
    $new = Tag::query()->where('scope', Tag::SCOPE_VAULT)->where('normalized_name', 'new tag')->sole();

    expect($ids)->toBe([$global->id, $local->id, (string) $new->id])
        ->and($new->vault_id)->toBe($vault->id);
    $this->assertDatabaseHas('audit_events', ['action' => 'tag.created']);

    expect($directory->resolveTagInput($alice, $vault->id, ['New Tag']))->toBe([(string) $new->id]);

    app(ScopedGrants::class)->grantOnFolder($alice, $folderA->id, $bob->principal_id, 'editor');
    expect(fn () => $directory->resolveTagInput($bob, $vault->id, ['Sneaky']))
        ->toThrow(ValidationException::class);
});

test('the vault tags page lists global tags read-only and deletes local tags', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $global = vaultigen_make_global_tag($admin, 'Compliance');
    $local = vaultigen_make_tag($alice, $vault, 'Production');
    $this->actingAs($alice);

    Livewire::test(ManageTags::class, ['vault' => $vault->id])
        ->assertSee('Compliance')
        ->assertSee('Production')
        ->assertTableFilterExists('scope')
        ->filterTable('scope', Tag::SCOPE_GLOBAL)
        ->assertCanSeeTableRecords([$global])
        ->assertCanNotSeeTableRecords([$local]);

    Livewire::test(ManageTags::class, ['vault' => $vault->id])
        ->callAction(TestAction::make('delete')->table($local))
        ->assertNotified('Tag deleted');

    expect(Tag::query()->find($local->id))->toBeNull()
        ->and(Tag::query()->find($global->id))->not->toBeNull();

    Livewire::test(ManageTags::class, ['vault' => $vault->id])
        ->assertActionHidden(TestAction::make('delete')->table($global));
});

test('tag input reveals nothing about a vault the actor has no access to', function () {
    ['alice' => $alice, 'dave' => $dave, 'vault' => $vault] = vaultigen_scope_fixture();
    vaultigen_make_tag($alice, $vault, 'Production');
    $directory = app(VaultDirectory::class);

    expect(fn () => $directory->resolveTagInput($dave, $vault->id, ['Production']))->toThrow(NotFoundHttpException::class)
        ->and(fn () => $directory->resolveTagInput($dave, $vault->id, ['Unknown']))->toThrow(NotFoundHttpException::class);
});
