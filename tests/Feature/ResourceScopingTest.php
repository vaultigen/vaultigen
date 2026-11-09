<?php

use App\Filament\Pages\ScopedAccess;
use App\Models\User;
use App\Services\ScopedGrants;
use App\Services\VaultAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

test('folder and entry grants make a vault visible without vault scope', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'dave' => $dave, 'vault' => $vault] = vaultigen_scope_fixture();
    $access = app(VaultAccess::class);

    expect($access->hasVaultScope($alice, $vault->id))->toBeTrue()
        ->and($access->hasVaultScope($bob, $vault->id))->toBeFalse()
        ->and($access->hasVaultAccess($bob, $vault->id))->toBeTrue()
        ->and($access->hasVaultAccess($carol, $vault->id))->toBeTrue()
        ->and($access->hasVaultAccess($dave, $vault->id))->toBeFalse()
        ->and($access->visibleVaults($bob)->pluck('id')->all())->toBe([$vault->id])
        ->and($access->visibleVaults($carol)->pluck('id')->all())->toBe([$vault->id])
        ->and($access->visibleVaults($dave)->count())->toBe(0)
        ->and($access->permission($bob, $vault->id))->toBeNull()
        ->and($access->allows($bob, $vault->id, 'metadata.view'))->toBeTrue()
        ->and($access->allows($dave, $vault->id, 'metadata.view'))->toBeFalse();
});

test('folder and entry scope restrict folders and entries precisely', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'dave' => $dave, 'vault' => $vault, 'folderA' => $folderA, 'rootEntry' => $rootEntry, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $access = app(VaultAccess::class);

    expect($access->visibleFolders($alice, $vault->id)->pluck('name')->sort()->values()->all())->toBe(['Operations', 'Servers'])
        ->and($access->visibleFolders($bob, $vault->id)->pluck('id')->all())->toBe([$folderA->id])
        ->and($access->visibleFolders($carol, $vault->id)->count())->toBe(0);

    expect($access->visibleEntries($alice, $vault->id)->pluck('id')->sort()->values()->all())->toBe(collect([$rootEntry->id, $entryA->id])->sort()->values()->all())
        ->and($access->visibleEntries($bob, $vault->id)->pluck('id')->all())->toBe([$entryA->id])
        ->and($access->visibleEntries($carol, $vault->id)->pluck('id')->all())->toBe([$entryA->id])
        ->and($access->visibleEntries($dave, $vault->id)->count())->toBe(0);
});

test('entry and folder authority combine vault, folder, and entry grants additively', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault, 'folderA' => $folderA, 'rootEntry' => $rootEntry, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $access = app(VaultAccess::class);

    expect($access->entryAuthority($bob, $entryA)['permission'])->toBe('editor')
        ->and($access->entryAuthority($bob, $rootEntry))->toBeNull()
        ->and($access->allowsOnEntry($bob, $entryA, 'entry.update'))->toBeTrue()
        ->and($access->allowsOnEntry($bob, $rootEntry, 'metadata.view'))->toBeFalse()
        ->and($access->allowsOnEntry($carol, $entryA, 'secret.reveal'))->toBeTrue()
        ->and($access->allowsOnEntry($carol, $entryA, 'entry.update'))->toBeFalse()
        ->and($access->allowsOnEntry($alice, $entryA, 'entry.history'))->toBeTrue()
        ->and($access->folderAuthority($bob, $folderA->id)['permission'])->toBe('editor')
        ->and($access->allowsOnFolder($bob, $folderA->id, 'entry.create'))->toBeTrue()
        ->and($access->allowsOnFolder($bob, $folderA->id, 'folder.rename'))->toBeFalse();
});

test('folder grants cannot create at root while vault scope can', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault, 'folderA' => $folderA, 'folderB' => $folderB] = vaultigen_scope_fixture();
    $access = app(VaultAccess::class);

    expect($access->canCreateEntry($alice, $vault->id, null))->toBeTrue()
        ->and($access->canCreateEntry($alice, $vault->id, $folderB->id))->toBeTrue()
        ->and($access->canCreateEntry($bob, $vault->id, null))->toBeFalse()
        ->and($access->canCreateEntry($bob, $vault->id, $folderA->id))->toBeTrue()
        ->and($access->canCreateEntry($bob, $vault->id, $folderB->id))->toBeFalse()
        ->and($access->canCreateEntry($carol, $vault->id, null))->toBeFalse()
        ->and($access->canCreateEntry($alice, $vault->id, 'not-a-folder'))->toBeFalse()
        ->and($access->canCreateInVault($bob, $vault->id))->toBeTrue()
        ->and($access->canCreateInVault($carol, $vault->id))->toBeFalse();
});

test('expired and revoked scoped grants stop access and visibility', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault, 'folderA' => $folderA, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $access = app(VaultAccess::class);
    $grantId = DB::table('folder_grants')->where('folder_id', $folderA->id)->where('principal_id', $bob->principal_id)->value('id');

    app(ScopedGrants::class)->revokeFolder($alice, $folderA->id, (string) $grantId);
    expect($access->hasVaultAccess($bob, $vault->id))->toBeFalse()
        ->and($access->visibleEntries($bob, $vault->id)->count())->toBe(0);

    app(ScopedGrants::class)->grantOnEntry($alice, $entryA->id, $bob->principal_id, 'viewer', now()->addMinute()->toDateTimeString());
    expect($access->allowsOnEntry($bob, $entryA, 'metadata.view'))->toBeTrue();
    $this->travel(2)->minutes();
    expect($access->allowsOnEntry($bob->refresh(), $entryA, 'metadata.view'))->toBeFalse()
        ->and($access->hasVaultAccess($bob, $vault->id))->toBeFalse();
});

test('vault grant holders lists every principal with a whole-vault grant', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_scope_fixture();

    $holders = app(VaultAccess::class)->vaultGrantHolders($vault->id);

    expect(collect($holders)->pluck('principal_id')->all())->toBe([$alice->principal_id])
        ->and(collect($holders)->pluck('scope')->unique()->values()->all())->toBe(['vault'])
        ->and($holders[0]['scope_label'])->toBe($vault->name);
});

test('explain reports the acting principal grant trail across every scope', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_scope_fixture();
    $access = app(VaultAccess::class);

    expect(collect($access->explain($alice, $vault->id))->pluck('scope')->unique()->values()->all())->toBe(['vault'])
        ->and(collect($access->explain($bob, $vault->id))->pluck('scope')->unique()->values()->all())->toBe(['folder'])
        ->and(collect($access->explain($carol, $vault->id))->pluck('scope')->unique()->values()->all())->toBe(['entry']);
});

test('scoped authority fails closed for inactive users and unknown abilities', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault, 'folderA' => $folderA, 'entryA' => $entryA] = vaultigen_scope_fixture();
    $access = app(VaultAccess::class);

    DB::table('users')->where('id', $bob->id)->update(['is_active' => false]);
    $bob->refresh();

    expect($access->folderAuthority($bob, $folderA->id))->toBeNull()
        ->and($access->entryAuthority($bob, $entryA))->toBeNull()
        ->and($access->hasVaultScope($bob, $vault->id))->toBeFalse()
        ->and($access->hasVaultAccess($bob, $vault->id))->toBeFalse()
        ->and($access->canCreateEntry($bob, $vault->id, $folderA->id))->toBeFalse()
        ->and($access->canCreateInVault($bob, $vault->id))->toBeFalse()
        ->and($access->explain($bob, $vault->id))->toBe([]);

    expect(fn () => $access->allowsOnEntry($alice, $entryA, 'teleport'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $access->allowsOnFolder($alice, $folderA->id, 'teleport'))->toThrow(InvalidArgumentException::class);
});

test('folder authority is null for an unknown folder', function () {
    ['alice' => $alice] = vaultigen_scope_fixture();

    expect(app(VaultAccess::class)->folderAuthority($alice, (string) Str::uuid()))->toBeNull();
});

test('invalid vault and folder identifiers fail closed across the access surface', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_scope_fixture();
    $access = app(VaultAccess::class);

    expect($access->permission($alice, 'not-a-uuid'))->toBeNull()
        ->and($access->allowsOnFolder($alice, 'not-a-uuid', 'entry.create'))->toBeFalse()
        ->and($access->canCreateEntry($alice, 'not-a-uuid', null))->toBeFalse()
        ->and($access->canCreateEntry($alice, $vault->id, (string) Str::uuid()))->toBeFalse()
        ->and($access->vaultGrantHolders('not-a-uuid'))->toBe([])
        ->and($access->visibleFolders($alice, 'not-a-uuid')->count())->toBe(0)
        ->and($access->visibleEntries($alice, 'not-a-uuid')->count())->toBe(0);
});

test('a tampered vault identifier cannot disclose another vault', function () {
    ['alice' => $alice, 'admin' => $admin, 'vault' => $vault, 'rootEntry' => $entry] = vaultigen_scope_fixture();
    $other = vaultigen_make_vault($admin, User::factory()->withTwoFactor()->create(), ['name' => 'Someone else']);

    Livewire::actingAs($alice)
        ->withQueryParams(['vault' => $vault->id, 'kind' => 'entry', 'target' => $entry->id])
        ->test(ScopedAccess::class)
        ->set('vault', $other->id);
})->throws(CannotUpdateLockedPropertyException::class);

test('every Filament component locks the identifiers it authorizes', function () {
    $identifiers = ['vault', 'vaultId', 'entryId', 'folderId', 'entryType', 'kind', 'target', 'team', 'run', 'user'];
    $unlocked = [];

    foreach (File::allFiles(app_path('Filament')) as $file) {
        $class = 'App\\Filament\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        if (! is_subclass_of($class, Component::class)) {
            continue;
        }

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $isInherited = $property->class !== $class || array_any(class_uses_recursive($class), fn (string $trait): bool => property_exists($trait, $property->getName()));
            $isIdentifier = in_array($property->getName(), $identifiers, true) || $property->getAttributes(Url::class) !== [];
            if (! $isInherited && $isIdentifier && $property->getAttributes(Locked::class) === []) {
                $unlocked[] = "{$class}::\${$property->getName()}";
            }
        }
    }

    expect($unlocked)->toBe([]);
});
