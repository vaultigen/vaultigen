<?php

use App\Console\Commands\PurgeTrash;
use App\Filament\Livewire\TrashEntries;
use App\Filament\Livewire\TrashFolders;
use App\Filament\Pages\EntryDetail;
use App\Filament\Pages\ManageFolders;
use App\Filament\Pages\Trash;
use App\Filament\Pages\VaultEntries;
use App\Models\Entry;
use App\Models\Folder;
use App\Services\AttachmentQuota;
use App\Services\AuditWriter;
use App\Services\EntryVault;
use App\Services\InstallationSettings;
use App\Services\ScopedGrants;
use App\Services\TrashLifecycle;
use App\Services\VaultAccess;
use App\Services\VaultAdministration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('managers trash and restore entries with placement fallback', function () {
    ['admin' => $admin, 'alice' => $alice, 'bob' => $bob, 'vault' => $vault] = vaultigen_entry_fixture();
    $folder = vaultigen_make_folder($alice, $vault, 'Operations');
    $entry = vaultigen_make_entry($alice, $vault);
    app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice, ['folder_id' => $folder->id]), 1);
    $entry = Entry::query()->findOrFail($entry->id);
    $lifecycle = app(TrashLifecycle::class);

    expect(fn () => $lifecycle->trashEntry($bob, $entry->id))->toThrow(AuthorizationException::class);

    $lifecycle->trashEntry($alice, $entry->id);
    $trashed = Entry::query()->findOrFail($entry->id);
    expect($trashed->deleted_at)->not->toBeNull()
        ->and($trashed->deleted_by_user_id)->toBe($alice->id)
        ->and($trashed->purge_after)->not->toBeNull();
    $this->assertDatabaseHas('audit_events', ['actor_id' => $alice->id, 'action' => 'entry.deleted']);

    expect(fn () => app(EntryVault::class)->find($alice, $entry->id))->toThrow(NotFoundHttpException::class);
    expect(app(EntryVault::class)->query($alice, $vault->id)->whereKey($entry->id)->exists())->toBeFalse();

    expect(fn () => $lifecycle->trashEntry($alice, $entry->id))->toThrow(NotFoundHttpException::class);
    expect(fn () => $lifecycle->restoreEntry($bob, $entry->id))->toThrow(AuthorizationException::class);

    expect(fn () => app(EntryVault::class)->reveal($bob, $entry->id, 'password'))->toThrow(NotFoundHttpException::class);

    $lifecycle->restoreEntry($alice, $entry->id);
    expect(Entry::query()->findOrFail($entry->id)->folder_id)->toBe($folder->id)
        ->and(app(EntryVault::class)->reveal($alice, $entry->id, 'password'))->toBe('correct-horse-battery-staple-42');
    $this->assertDatabaseHas('audit_events', ['actor_id' => $alice->id, 'action' => 'entry.restored']);

    // A trashed folder that is deleted before the entry is restored forces the
    // entry back to the vault root.
    $lifecycle->trashEntry($alice, $entry->id);
    $lifecycle->trashFolder($alice, $folder->id);
    $lifecycle->restoreEntry($alice, $entry->id);
    expect(Entry::query()->findOrFail($entry->id)->folder_id)->toBeNull();

    $lifecycle->restoreFolder($alice, $folder->id);
    expect(Folder::query()->findOrFail($folder->id)->deleted_at)->toBeNull();
});

test('folders can only be deleted while empty and trash listings are manager scoped', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault] = vaultigen_entry_fixture();
    $folder = vaultigen_make_folder($alice, $vault, 'Operations');
    vaultigen_make_entry($alice, $vault, ['folder_id' => $folder->id]);
    $entry = Entry::query()->where('folder_id', $folder->id)->sole();
    $lifecycle = app(TrashLifecycle::class);

    expect(fn () => $lifecycle->trashFolder($alice, $folder->id))->toThrow(ValidationException::class);
    expect(fn () => $lifecycle->trashFolder($bob, $folder->id))->toThrow(AuthorizationException::class);

    $lifecycle->trashEntry($alice, $entry->id);
    $lifecycle->trashFolder($alice, $folder->id);
    expect($lifecycle->trashedEntries($alice, $vault->id)->count())->toBe(1)
        ->and($lifecycle->trashedFolders($alice, $vault->id)->count())->toBe(1);
    $this->assertDatabaseHas('audit_events', ['action' => 'folder.deleted']);

    expect(fn () => $lifecycle->trashedEntries($bob, $vault->id))->toThrow(NotFoundHttpException::class);
    expect(fn () => $lifecycle->trashedFolders($bob, $vault->id))->toThrow(NotFoundHttpException::class);
    expect(fn () => $lifecycle->restoreFolder($bob, $folder->id))->toThrow(AuthorizationException::class);

    $lifecycle->restoreEntry($alice, $entry->id);
    $lifecycle->restoreFolder($alice, $folder->id);
    $this->assertDatabaseHas('audit_events', ['action' => 'folder.restored']);
    expect(fn () => $lifecycle->restoreFolder($alice, $folder->id))->toThrow(NotFoundHttpException::class);
    expect(fn () => $lifecycle->restoreEntry($alice, $entry->id))->toThrow(NotFoundHttpException::class);
});

test('vault state blocks trashing and restoring entries and folders', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $lifecycle = app(TrashLifecycle::class);
    $folder = vaultigen_make_folder($alice, $vault, 'Operations');
    $entry = vaultigen_make_entry($alice, $vault);

    app(VaultAdministration::class)->setState($admin, $vault->id, 'read_only');
    expect(fn () => $lifecycle->trashEntry($alice, $entry->id))->toThrow(ValidationException::class);
    expect(fn () => $lifecycle->trashFolder($alice, $folder->id))->toThrow(ValidationException::class);
});

test('purging removes due entries and their dependent rows but keeps the rest', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $keep = vaultigen_make_entry($alice, $vault, ['title' => 'Keep me']);
    app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice, ['title' => 'Updated']), 1);
    $entry = Entry::query()->findOrFail($entry->id);
    app(ScopedGrants::class)->grantOnEntry($alice, $entry->id, $bob->principal_id, 'viewer');
    app(EntryVault::class)->reveal($bob, $entry->id, 'password');
    $lifecycle = app(TrashLifecycle::class);

    $lifecycle->trashEntry($alice, $entry->id);
    DB::table('entries')->where('id', $entry->id)->update(['purge_after' => now()->subMinute()]);
    $lifecycle->trashEntry($alice, $keep->id);

    $result = $lifecycle->purgeDue();
    expect($result['entries'])->toBe(1)->and($result['failures'])->toBe(0);

    $this->assertDatabaseMissing('entries', ['id' => $entry->id]);
    $this->assertDatabaseMissing('entry_revisions', ['entry_id' => $entry->id]);
    $this->assertDatabaseMissing('entry_grants', ['entry_id' => $entry->id]);
    $this->assertDatabaseMissing('secret_reveals', ['entry_id' => $entry->id]);
    $this->assertDatabaseHas('entries', ['id' => $keep->id]);
    $this->assertDatabaseHas('audit_events', ['action' => 'entry.purged']);

    // A direct purge of a row that is not due is a no-op.
    $lifecycle->purgeEntry($keep->id);
    $this->assertDatabaseHas('entries', ['id' => $keep->id]);
    $lifecycle->purgeEntry((string) Str::uuid());
});

test('folder purge waits for referencing entries and then completes', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $folder = vaultigen_make_folder($alice, $vault, 'Operations');
    $entry = vaultigen_make_entry($alice, $vault, ['folder_id' => $folder->id]);
    $lifecycle = app(TrashLifecycle::class);

    $lifecycle->trashEntry($alice, $entry->id);
    $lifecycle->trashFolder($alice, $folder->id);
    DB::table('entries')->where('id', $entry->id)->update(['purge_after' => now()->addDay()]);
    DB::table('folders')->where('id', $folder->id)->update(['purge_after' => now()->subMinute()]);

    // The folder is skipped while the trashed entry still references it, even
    // when purged directly.
    $lifecycle->purgeDue();
    $this->assertDatabaseHas('folders', ['id' => $folder->id]);
    expect(fn () => $lifecycle->purgeFolder($folder->id))->toThrow(RuntimeException::class);
    $lifecycle->purgeFolder((string) Str::uuid());

    DB::table('entries')->where('id', $entry->id)->update(['purge_after' => now()->subMinute()]);
    $lifecycle->purgeDue();
    $this->assertDatabaseMissing('folders', ['id' => $folder->id]);
    $this->assertDatabaseHas('audit_events', ['action' => 'folder.purged']);
});

test('revision retention prunes by count and age', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    foreach (['one', 'two', 'three'] as $title) {
        $entry = Entry::query()->findOrFail($entry->id);
        app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice, ['title' => $title]), $entry->lock_version);
    }
    $lifecycle = app(TrashLifecycle::class);

    DB::table('installation_settings')->where('id', 1)->update(['revision_retention_count' => 1, 'revision_retention_days' => 0]);
    expect($lifecycle->pruneRevisions())->toBe(2);
    expect(DB::table('entry_revisions')->where('entry_id', $entry->id)->count())->toBe(1);
    $this->assertDatabaseHas('audit_events', ['action' => 'entry.revisions_pruned']);

    // Nothing left to prune is a no-op.
    expect($lifecycle->pruneRevisions())->toBe(0);

    // Age-only pruning removes old revisions without a count limit.
    DB::table('installation_settings')->where('id', 1)->update(['revision_retention_count' => 0, 'revision_retention_days' => 1]);
    DB::table('entry_revisions')->where('entry_id', $entry->id)->update(['created_at' => now()->subDays(5)]);
    expect($lifecycle->pruneRevisions())->toBe(1);

    // Both limits disabled short-circuits.
    DB::table('installation_settings')->where('id', 1)->update(['revision_retention_count' => 0, 'revision_retention_days' => 0]);
    expect($lifecycle->pruneRevisions())->toBe(0);
});

test('a failing purge item is reported as a sanitized failure and retried', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $folder = vaultigen_make_folder($alice, $vault, 'Operations');
    $lifecycle = app(TrashLifecycle::class);
    $lifecycle->trashEntry($alice, $entry->id);
    $lifecycle->trashFolder($alice, $folder->id);
    DB::table('entries')->where('id', $entry->id)->update(['purge_after' => now()->subMinute()]);
    DB::table('folders')->where('id', $folder->id)->update(['purge_after' => now()->subMinute()]);

    $mock = Mockery::mock(TrashLifecycle::class, [
        app(AuditWriter::class),
        app(VaultAccess::class),
        app(InstallationSettings::class),
        app(AttachmentQuota::class),
    ])->makePartial();
    $mock->shouldReceive('purgeEntry')->andThrow(new RuntimeException('boom'));
    $mock->shouldReceive('purgeFolder')->andThrow(new RuntimeException('boom'));

    $result = $mock->purgeDue();
    expect($result['failures'])->toBe(2);

    $this->assertDatabaseHas('audit_events', ['action' => 'entry.purged', 'outcome' => 'failed']);
    $this->assertDatabaseHas('audit_events', ['action' => 'folder.purged', 'outcome' => 'failed']);
});

test('the purge command reports counters and honours the run lock', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    app(TrashLifecycle::class)->trashEntry($alice, $entry->id);
    DB::table('entries')->where('id', $entry->id)->update(['purge_after' => now()->subMinute()]);

    $this->artisan(PurgeTrash::class)->expectsOutputToContain('Purged 1 entries')->assertSuccessful();

    $entry = vaultigen_make_entry($alice, $vault, ['title' => 'Second']);
    app(TrashLifecycle::class)->trashEntry($alice, $entry->id);
    DB::table('entries')->where('id', $entry->id)->update(['purge_after' => now()->subMinute()]);

    $lock = Cache::lock('vaultigen-purge-trash', 300);
    expect($lock->acquire())->toBeTrue();
    $this->artisan(PurgeTrash::class)->expectsOutputToContain('already in progress')->assertSuccessful();
    $lock->release();
});

test('the trash page is manager only and restores entries and folders', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault] = vaultigen_entry_fixture();
    $folder = vaultigen_make_folder($alice, $vault, 'Operations');
    $entry = vaultigen_make_entry($alice, $vault);
    $lifecycle = app(TrashLifecycle::class);
    $lifecycle->trashEntry($alice, $entry->id);
    $lifecycle->trashFolder($alice, $folder->id);
    $this->actingAs($alice);

    Livewire::test(Trash::class, ['vault' => $vault->id])
        ->assertSee('Operations')
        ->assertSee($entry->title);

    Livewire::test(TrashEntries::class, ['vault' => $vault->id])
        ->callTableAction('restore', $entry)
        ->assertNotified('Entry restored');
    Livewire::test(TrashFolders::class, ['vault' => $vault->id])
        ->callTableAction('restore', $folder)
        ->assertNotified('Folder restored');

    $this->actingAs($bob);
    Livewire::test(Trash::class, ['vault' => $vault->id])->assertNotFound();
    Livewire::test(Trash::class, ['vault' => ''])->assertNotFound();
    $this->actingAs($alice);
    Livewire::test(Trash::class, ['vault' => $vault->id])->call('$refresh')->assertOk();
});

test('entry and folder delete actions are available and audited', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $folder = vaultigen_make_folder($alice, $vault, 'Ops');
    $this->actingAs($alice);

    Livewire::test(EntryDetail::class, ['entryId' => $entry->id])
        ->callAction('delete')
        ->assertNotified('Entry moved to trash');
    expect(Entry::query()->findOrFail($entry->id)->deleted_at)->not->toBeNull();

    Livewire::test(ManageFolders::class, ['vault' => $vault->id])
        ->callTableAction('delete', $folder)
        ->assertNotified('Folder moved to trash');
    expect(Folder::query()->findOrFail($folder->id)->deleted_at)->not->toBeNull();
});

test('trash actions surface validation failures from frozen vaults', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $folder = vaultigen_make_folder($alice, $vault, 'Ops');
    $emptyFolder = vaultigen_make_folder($alice, $vault, 'Empty');
    $trashed = vaultigen_make_entry($alice, $vault, ['title' => 'Trashed']);
    $live = vaultigen_make_entry($alice, $vault, ['title' => 'Live']);
    vaultigen_make_entry($alice, $vault, ['title' => 'In folder', 'folder_id' => $folder->id]);
    $lifecycle = app(TrashLifecycle::class);
    $lifecycle->trashEntry($alice, $trashed->id);
    $lifecycle->trashFolder($alice, $emptyFolder->id);
    app(VaultAdministration::class)->setState($admin, $vault->id, 'read_only');
    $this->actingAs($alice);

    Livewire::test(TrashEntries::class, ['vault' => $vault->id])
        ->callTableAction('restore', $trashed)
        ->assertNotified('Not restored');

    Livewire::test(TrashFolders::class, ['vault' => $vault->id])
        ->callTableAction('restore', $emptyFolder)
        ->assertNotified('Not restored');

    Livewire::test(EntryDetail::class, ['entryId' => $live->id])
        ->callAction('delete')
        ->assertNotified('Entry not deleted');

    Livewire::test(ManageFolders::class, ['vault' => $vault->id])
        ->callTableAction('delete', $folder)
        ->assertNotified('Folder not deleted');
});

test('the vault state banner explains frozen vaults', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    vaultigen_make_entry($alice, $vault);
    app(VaultAdministration::class)->setState($admin, $vault->id, 'read_only');
    $this->actingAs($alice);

    Livewire::test(VaultEntries::class, ['vault' => $vault->id])
        ->assertSee('This vault is read-only');
});
