<?php

use App\Filament\Pages\ManageTags;
use App\Models\EntryGrant;
use App\Models\FolderGrant;
use App\Models\Tag;
use App\Services\EntryVault;
use App\Services\ScopedGrants;
use App\Services\VaultAdministration;
use App\Services\VaultDirectory;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('tag names are unique per vault and validated', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $directory = app(VaultDirectory::class);
    vaultigen_make_tag($alice, $vault, 'Production');

    expect(fn () => vaultigen_make_tag($alice, $vault, 'production'))->toThrow(ValidationException::class);
    expect(fn () => vaultigen_make_tag($alice, $vault, 'Dev', 'rainbow'))->toThrow(ValidationException::class);
    expect(fn () => $directory->updateTag($alice, (string) Str::uuid(), ['name' => 'X']))->toThrow(NotFoundHttpException::class);
});

test('only a vault-scope editor can manage tags', function () {
    ['admin' => $admin, 'alice' => $alice, 'bob' => $bob, 'vault' => $vault, 'folderA' => $folderA] = vaultigen_scope_fixture();
    $directory = app(VaultDirectory::class);

    expect(fn () => $directory->createTag($bob, $vault->id, ['name' => 'Nope']))->toThrow(AuthorizationException::class);

    app(ScopedGrants::class)->grantOnFolder($alice, $folderA->id, $bob->principal_id, 'editor');
    expect(fn () => $directory->createTag($bob, $vault->id, ['name' => 'Nope']))->toThrow(AuthorizationException::class);

    app(VaultAdministration::class)->setState($admin, $vault->id, 'read_only');
    expect(fn () => $directory->createTag($alice, $vault->id, ['name' => 'Nope']))->toThrow(ValidationException::class);
});

test('tag options are hidden from folder-only users', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault] = vaultigen_scope_fixture();
    vaultigen_make_tag($alice, $vault, 'Production');
    $directory = app(VaultDirectory::class);

    expect($directory->tagOptions($alice, $vault->id))->toHaveCount(1)
        ->and($directory->tagOptions($bob, $vault->id))->toBe([]);
});

test('entries can only reference tags that belong to their vault', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_scope_fixture();
    $second = vaultigen_make_vault($admin, $alice, ['name' => 'Second']);
    $foreignTag = vaultigen_make_tag($alice, $second, 'Foreign');
    $directory = app(VaultDirectory::class);

    expect(fn () => $directory->validateTags($vault->id, [$foreignTag->id]))->toThrow(ValidationException::class)
        ->and($directory->validateTags($vault->id, []))->toBe([])
        ->and($directory->validateTags($vault->id, ['', null, 123]))->toBe([]);
});

test('the tags management page creates and edits records', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault] = vaultigen_entry_fixture();
    $this->actingAs($alice);

    Livewire::test(ManageTags::class, ['vault' => $vault->id])
        ->assertSee('Tags')
        ->callAction('create', ['name' => 'Production', 'color' => 'success'])
        ->assertNotified('Tag created');

    $tag = Tag::query()->where('normalized_name', 'production')->sole();

    Livewire::test(ManageTags::class, ['vault' => $vault->id])
        ->callAction(TestAction::make('edit')->table($tag), ['name' => 'Prod', 'color' => 'warning'])
        ->assertNotified('Tag updated');

    expect($tag->fresh()->name)->toBe('Prod');

    $this->actingAs($bob);
    Livewire::test(ManageTags::class, ['vault' => $vault->id])->assertNotFound();
});

test('tag creation validates against duplicate empty names', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();

    expect(fn () => vaultigen_make_tag($alice, $vault, '   '))->toThrow(ValidationException::class);
});

test('the database rejects duplicate tag normalized names', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    vaultigen_make_tag($alice, $vault, 'Prod');

    expect(fn () => DB::table('tags')->insert([
        'id' => (string) Str::uuid(),
        'vault_id' => $vault->id,
        'name' => 'prod',
        'normalized_name' => 'prod',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('page forms surface validation failures as notifications', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    vaultigen_make_tag($alice, $vault, 'Prod');
    $otherTag = vaultigen_make_tag($alice, $vault, 'Dev');
    $this->actingAs($alice);

    Livewire::test(ManageTags::class, ['vault' => $vault->id])
        ->callAction('create', ['name' => 'Prod'])
        ->assertNotified('Tag not created');

    Livewire::test(ManageTags::class, ['vault' => $vault->id])
        ->callAction(TestAction::make('edit')->table($otherTag), ['name' => 'Prod'])
        ->assertNotified('Tag not updated');

    $this->get(ManageTags::getUrl(['vault' => $vault->id]))->assertOk()->assertSee('Tags');
});

test('tag input ignores non-string and unnormalizable values', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $directory = app(VaultDirectory::class);

    expect($directory->resolveTagInput($alice, $vault->id, [123, "\xFF", 'Tagged']))->toHaveCount(1);
});

test('the tags page surfaces delete warnings and inactive vault failures', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $used = vaultigen_make_tag($alice, $vault, 'Production');
    vaultigen_make_entry($alice, $vault, ['title' => 'Tagged', 'tags' => [$used->id]]);
    $inactive = vaultigen_make_tag($alice, $vault, 'Inactive');
    $this->actingAs($alice);

    Livewire::test(ManageTags::class, ['vault' => $vault->id])
        ->callTableAction('delete', $used)
        ->assertNotified('Tag deleted');

    app(VaultAdministration::class)->setState($admin, $vault->id, 'read_only');
    Livewire::test(ManageTags::class, ['vault' => $vault->id])
        ->callTableAction('delete', $inactive)
        ->assertNotified('Tag not deleted');
});

test('organization models expose their relations', function () {
    ['alice' => $alice, 'vault' => $vault, 'folderA' => $folderA, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $tag = vaultigen_make_tag($alice, $vault, 'Production');
    app(EntryVault::class)->update($alice, $entryA->id, vaultigen_entry_update($alice, [
        'title' => 'Folder entry', 'folder_id' => $folderA->id, 'tags' => [$tag->id],
    ]), $entryA->lock_version);

    expect($vault->folders()->count())->toBe(2)
        ->and($vault->tags()->count())->toBe(1)
        ->and($folderA->vault->id)->toBe($vault->id)
        ->and($folderA->entries()->count())->toBe(1)
        ->and($tag->vault->id)->toBe($vault->id)
        ->and($tag->entries()->count())->toBe(1);

    $folderGrant = FolderGrant::query()->where('folder_id', $folderA->id)->firstOrFail();
    expect($folderGrant->folder?->id)->toBe($folderA->id)
        ->and($folderGrant->principal)->not->toBeNull()
        ->and($folderGrant->grantedBy->id)->toBe($alice->id);

    $entryGrant = EntryGrant::query()->where('entry_id', $entryA->id)->firstOrFail();
    expect($entryGrant->entry?->id)->toBe($entryA->id)
        ->and($entryGrant->principal)->not->toBeNull()
        ->and($entryGrant->grantedBy->id)->toBe($alice->id);
});
