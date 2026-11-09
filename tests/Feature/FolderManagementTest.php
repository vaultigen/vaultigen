<?php

use App\Filament\Pages\ManageFolders;
use App\Filament\Pages\ScopedAccess;
use App\Models\Folder;
use App\Models\User;
use App\Services\ScopedGrants;
use App\Services\VaultAdministration;
use App\Services\VaultFolders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('folders are created with a normalized unique name per vault', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();

    $folder = vaultigen_make_folder($alice, $vault, '  Operations  ');

    expect($folder->name)->toBe('Operations')
        ->and($folder->normalized_name)->toBe('operations')
        ->and($folder->depth)->toBe(0)
        ->and($folder->parent_id)->toBeNull()
        ->and($folder->lock_version)->toBe(1);

    expect(fn () => vaultigen_make_folder($alice, $vault, 'operations'))->toThrow(ValidationException::class);
    expect(fn () => vaultigen_make_folder($alice, $vault, '   '))->toThrow(ValidationException::class);
});

test('other vaults may reuse a folder name', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    vaultigen_make_folder($alice, $vault, 'Shared');
    $second = vaultigen_make_vault($admin, $alice, ['name' => 'Second']);

    expect(vaultigen_make_folder($alice, $second, 'Shared')->name)->toBe('Shared');
});

test('only a manager can create or rename folders in an active vault', function () {
    ['admin' => $admin, 'alice' => $alice, 'bob' => $bob, 'vault' => $vault] = vaultigen_entry_fixture();
    $folder = vaultigen_make_folder($alice, $vault, 'Operations');
    app(ScopedGrants::class)->grantOnFolder($alice, $folder->id, $bob->principal_id, 'editor');
    $folders = app(VaultFolders::class);

    expect(fn () => $folders->create($bob, $vault->id, 'Nope'))->toThrow(AuthorizationException::class);
    expect(fn () => $folders->rename($bob, $folder->id, 'Nope', 1))->toThrow(AuthorizationException::class);

    app(VaultAdministration::class)->setState($admin, $vault->id, 'read_only');
    expect(fn () => $folders->create($alice, $vault->id, 'Locked'))->toThrow(ValidationException::class)
        ->and(fn () => $folders->rename($alice, $folder->id, 'Locked', 1))->toThrow(ValidationException::class);

    expect(DB::table('folders')->where('vault_id', $vault->id)->count())->toBe(1);
});

test('rename uses optimistic locking and rejects a duplicate name', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $folder = vaultigen_make_folder($alice, $vault, 'Operations');
    vaultigen_make_folder($alice, $vault, 'Servers');
    $folders = app(VaultFolders::class);

    $folders->rename($alice, $folder->id, '  Operations & support ', 1);
    expect($folder->fresh()->name)->toBe('Operations & support')
        ->and($folder->fresh()->normalized_name)->toBe('operations & support')
        ->and($folder->fresh()->lock_version)->toBe(2);

    expect(fn () => $folders->rename($alice, $folder->id, 'Servers', 2))->toThrow(ValidationException::class);
    expect(fn () => $folders->rename($alice, $folder->id, 'Anything', 1))->toThrow(ValidationException::class);
});

test('folder resolution and options respect effective access', function () {
    ['alice' => $alice, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $dave = User::factory()->withTwoFactor()->create();
    $ops = vaultigen_make_folder($alice, $vault, 'Operations');
    vaultigen_make_folder($alice, $vault, 'Servers');
    app(ScopedGrants::class)->grantOnFolder($alice, $ops->id, $carol->principal_id, 'viewer');
    $folders = app(VaultFolders::class);

    expect($folders->resolve($alice, $ops->id)->name)->toBe('Operations')
        ->and($folders->options($alice, $vault->id))->toHaveCount(2)
        ->and($folders->options($carol, $vault->id))->toBe([$ops->id => 'Operations']);

    expect(fn () => $folders->resolve($dave, $ops->id))->toThrow(NotFoundHttpException::class);
    expect(fn () => $folders->resolve($alice, (string) Str::uuid()))->toThrow(NotFoundHttpException::class);
});

test('the database rejects nested or duplicated folders', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $folder = vaultigen_make_folder($alice, $vault, 'Operations');

    expect(fn () => DB::table('folders')->insert([
        'id' => (string) Str::uuid(),
        'vault_id' => $vault->id,
        'parent_id' => $folder->id,
        'name' => 'Nested',
        'normalized_name' => 'nested',
        'depth' => 1,
        'lock_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('folders')->insert([
        'id' => (string) Str::uuid(),
        'vault_id' => $vault->id,
        'parent_id' => null,
        'name' => 'operations',
        'normalized_name' => 'operations',
        'depth' => 0,
        'lock_version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('the folder administration page manages folders for managers only', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    vaultigen_make_folder($alice, $vault, 'Operations');
    $this->actingAs($alice);

    Livewire::test(ManageFolders::class, ['vault' => $vault->id])
        ->assertSee('Operations')
        ->callAction('create', ['name' => 'Servers'])
        ->assertNotified('Folder created');

    $servers = Folder::query()->where('normalized_name', 'servers')->sole();

    Livewire::test(ManageFolders::class, ['vault' => $vault->id])
        ->assertTableActionHasUrl('edit', ScopedAccess::getUrl(['vault' => $vault->id, 'kind' => 'folder', 'target' => $servers->id]), $servers);

    Livewire::test(ManageFolders::class, ['vault' => $vault->id])
        ->callAction('create', ['name' => 'Operations'])
        ->assertNotified('Folder not created');

    $this->actingAs($bob);
    Livewire::test(ManageFolders::class, ['vault' => $vault->id])->assertNotFound();

    $this->actingAs($carol);
    Livewire::test(ManageFolders::class, ['vault' => $vault->id])->assertNotFound();
    Livewire::test(ManageFolders::class, ['vault' => ''])->assertNotFound();

    $this->actingAs($alice);
    Livewire::test(ManageFolders::class, ['vault' => $vault->id])->call('$refresh')->assertOk();
});

test('folder administration reports validation failures and renders over http', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    vaultigen_make_folder($alice, $vault, 'Operations');
    vaultigen_make_folder($alice, $vault, 'Servers');
    $this->actingAs($alice);

    Livewire::test(ManageFolders::class, ['vault' => $vault->id])
        ->callAction('create', ['name' => 'Operations'])
        ->assertNotified('Folder not created');

    $this->get(ManageFolders::getUrl(['vault' => $vault->id]))->assertOk()->assertSee('Folders');
});
