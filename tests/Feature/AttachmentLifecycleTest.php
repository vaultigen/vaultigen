<?php

use App\Console\Commands\PurgeTrash;
use App\Models\Entry;
use App\Models\FileAttachment;
use App\Services\Crypto\VaultKeyManager;
use App\Services\EntryAttachments;
use App\Services\EntryVault;
use App\Services\TrashLifecycle;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    vaultigen_fake_attachment_disk();
});

test('purging a trashed entry removes its encrypted attachment objects', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachment = vaultigen_upload_attachment($alice, $entry, 'body', 'x.txt');
    $path = $attachment->random_storage_path;
    $lifecycle = app(TrashLifecycle::class);

    $lifecycle->trashEntry($alice, $entry->id);
    DB::table('entries')->where('id', $entry->id)->update(['purge_after' => now()->subMinute()]);

    // Download is denied while the entry is trashed.
    expect(fn () => app(EntryAttachments::class)->authorizeDownload($alice, $attachment->id))
        ->toThrow(NotFoundHttpException::class);

    $result = $lifecycle->purgeDue();
    expect($result['entries'])->toBe(1)
        ->and($result['attachments'])->toBe(0)
        ->and($result['failures'])->toBe(0);

    $this->assertDatabaseMissing('file_attachments', ['id' => $attachment->id]);
    $this->assertDatabaseMissing('entries', ['id' => $entry->id]);
    expect(Storage::disk('attachments')->exists($path))->toBeFalse();
    $this->assertDatabaseHas('audit_events', ['action' => 'entry.purged']);

    // A second purge is a no-op.
    expect($lifecycle->purgeDue()['entries'])->toBe(0);
});

test('retention purges individually removed attachments and releases expired reservations', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachment = vaultigen_upload_attachment($alice, $entry, 'body', 'x.txt');
    $path = $attachment->random_storage_path;
    $lifecycle = app(TrashLifecycle::class);

    app(EntryAttachments::class)->delete($alice, $attachment->id);
    DB::table('file_attachments')->where('id', $attachment->id)->update(['purge_after' => now()->subMinute()]);

    DB::table('attachment_reservations')->insert([
        'id' => (string) Str::uuid(),
        'vault_id' => $vault->id,
        'requested_by_user_id' => $alice->id,
        'reserved_bytes' => 10,
        'expires_at' => now()->subMinute(),
        'state' => 'reserved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = $lifecycle->purgeDue();

    expect($result['attachments'])->toBe(1)
        ->and($result['reservations'])->toBe(1);
    $this->assertDatabaseMissing('file_attachments', ['id' => $attachment->id]);
    expect(Storage::disk('attachments')->exists($path))->toBeFalse();
    $this->assertDatabaseHas('audit_events', ['action' => 'attachment.deleted']);

    // A direct purge of a not-yet-due attachment is a no-op.
    $lifecycle->purgeAttachment((string) Str::uuid());
});

test('a failed object-store removal keeps the attachment eligible and writes a sanitized failure', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachment = vaultigen_upload_attachment($alice, $entry, 'body', 'x.txt');
    $lifecycle = app(TrashLifecycle::class);
    app(EntryAttachments::class)->delete($alice, $attachment->id);
    DB::table('file_attachments')->where('id', $attachment->id)->update(['purge_after' => now()->subMinute()]);

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('delete')->once()->andThrow(new RuntimeException('disk offline'));
    Storage::shouldReceive('disk')->with('attachments')->andReturn($disk);

    $result = $lifecycle->purgeDue();
    expect($result['attachments'])->toBe(0)
        ->and($result['failures'])->toBe(1);
    $this->assertDatabaseHas('file_attachments', ['id' => $attachment->id]);
    $this->assertDatabaseHas('audit_events', ['action' => 'attachment.deleted', 'outcome' => 'failed']);
});

test('the purge command reports attachment and reservation counters', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachment = vaultigen_upload_attachment($alice, $entry, 'body', 'x.txt');
    app(EntryAttachments::class)->delete($alice, $attachment->id);
    DB::table('file_attachments')->where('id', $attachment->id)->update(['purge_after' => now()->subMinute()]);

    $this->artisan(PurgeTrash::class)->expectsOutputToContain('1 attachments')->assertSuccessful();
});

test('cross-vault entry moves re-wrap file keys and metadata without re-encrypting bytes', function () {
    ['admin' => $admin, 'alice' => $alice, 'bob' => $bob, 'vault' => $source] = vaultigen_entry_fixture();
    $destination = vaultigen_make_vault($admin, $alice, ['name' => 'Destination']);
    $entry = vaultigen_make_entry($alice, $source);
    $attachment = vaultigen_upload_attachment($alice, $entry, 'movable bytes', 'move.txt');
    $path = $attachment->random_storage_path;
    $bytes = Storage::disk('attachments')->get($path);

    app(EntryVault::class)->move($alice, $entry->id, $destination->id, null);

    $moved = FileAttachment::query()->findOrFail($attachment->id);
    expect($moved->vault_id)->toBe($destination->id)
        ->and($moved->vault_key_version_id)->toBe(app(VaultKeyManager::class)->activeVersionId($destination->id))
        ->and(Storage::disk('attachments')->get($path))->toBe($bytes)
        ->and(vaultigen_decrypt_attachment($moved))->toBe('movable bytes');

    // A source-only viewer loses access because the attachment now belongs to
    // the destination vault.
    expect(fn () => app(EntryAttachments::class)->authorizeDownload($bob, $moved->id))
        ->toThrow(NotFoundHttpException::class);
    $this->assertDatabaseHas('audit_events', ['action' => 'entry.moved']);
});

test('cross-vault moves drop already-trashed attachments and reject a destination quota overflow', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $source] = vaultigen_entry_fixture();
    $destination = vaultigen_make_vault($admin, $alice, ['name' => 'Destination']);
    $entry = vaultigen_make_entry($alice, $source);
    $keep = vaultigen_upload_attachment($alice, $entry, 'keep me', 'keep.txt');
    $drop = vaultigen_upload_attachment($alice, $entry, 'drop me', 'drop.txt');
    $dropPath = $drop->random_storage_path;
    app(EntryAttachments::class)->delete($alice, $drop->id);

    app(EntryVault::class)->move($alice, $entry->id, $destination->id, null);

    $this->assertDatabaseMissing('file_attachments', ['id' => $drop->id]);
    $this->assertDatabaseHas('file_attachments', ['id' => $keep->id, 'vault_id' => $destination->id]);
    expect(Storage::disk('attachments')->exists($dropPath))->toBeFalse();

    // A second entry whose live attachment would exceed the destination limit.
    $second = vaultigen_make_entry($alice, $source, ['title' => 'Second']);
    $big = vaultigen_upload_attachment($alice, $second, str_repeat('x', 10), 'big.txt');
    config(['vaultigen.attachments.max_bytes_per_vault' => 10]);

    expect(fn () => app(EntryVault::class)->move($alice, $second->id, $destination->id, null))
        ->toThrow(ValidationException::class);
    expect(FileAttachment::query()->findOrFail($big->id)->vault_id)->toBe($source->id);
});

test('restoring an entry revision reinstates the attachment membership it recorded', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $first = vaultigen_upload_attachment($alice, $entry, 'one', 'one.txt');

    // Update records a revision whose snapshot includes the first attachment.
    app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice, ['title' => 'Renamed']), 1);
    $entry = Entry::query()->findOrFail($entry->id);
    $revision = app(EntryVault::class)->history($alice, $entry->id)->first();
    $this->travel(1)->second();
    $second = vaultigen_upload_attachment($alice, $entry, 'two', 'two.txt');

    expect(app(EntryAttachments::class)->forEntry($entry)->pluck('id')->all())->toBe([$first->id, $second->id]);

    app(EntryVault::class)->restore($alice, $entry->id, $revision->id);

    expect(app(EntryAttachments::class)->forEntry($entry)->pluck('id')->all())->toBe([$first->id])
        ->and(FileAttachment::query()->findOrFail($second->id)->deleted_at)->not->toBeNull();
});
