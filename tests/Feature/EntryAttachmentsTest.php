<?php

use App\Models\FileAttachment;
use App\Services\Crypto\AttachmentCipher;
use App\Services\Crypto\VaultKeyManager;
use App\Services\EntryAttachments;
use App\Services\VaultAdministration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    vaultigen_fake_attachment_disk();
});

function vaultigen_raw_upload(string $name, string $contents): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'vaultigen-upload-');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, 'text/plain', UPLOAD_ERR_OK, true);
}

test('upload encrypts the bytes and metadata and never stores the filename in plaintext', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $contents = str_repeat('synthetic attachment bytes ', 100);

    $attachment = vaultigen_upload_attachment($alice, $entry, $contents, 'quarterly report.txt');

    expect($attachment->byte_size)->toBe(strlen($contents))
        ->and($attachment->state)->toBe(FileAttachment::STATE_AVAILABLE)
        ->and($attachment->stream_format_version)->toBe(1)
        ->and($attachment->wrapped_file_key_algorithm)->toBe('xchacha20-poly1305')
        ->and($attachment->ciphertext_checksum)->not->toBe('')
        ->and($attachment->encrypted_metadata)->not->toContain('quarterly report')
        ->and(vaultigen_decrypt_attachment($attachment))->toBe($contents);

    // The plaintext filename is not present in the database row or metadata column.
    $row = DB::table('file_attachments')->where('id', $attachment->id)->first();
    expect(json_encode($row))->not->toContain('quarterly report');

    $stored = Storage::disk('attachments')->get($attachment->random_storage_path);
    expect($stored)->not->toContain('synthetic attachment bytes')
        ->and($stored)->toBeString();

    $this->assertDatabaseHas('audit_events', ['actor_id' => $alice->id, 'action' => 'attachment.uploaded']);
    $this->assertDatabaseHas('attachment_reservations', ['attachment_id' => $attachment->id, 'state' => 'committed']);
});

test('metadata decrypts the original filename and detected type', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);

    $attachment = vaultigen_upload_attachment($alice, $entry, 'plain text', 'readme.txt');
    $metadata = app(EntryAttachments::class)->metadata($attachment);

    expect($metadata['filename'])->toBe('readme.txt')
        ->and($metadata['mime'])->toStartWith('text/plain');
});

test('upload sanitizes hostile filenames', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachments = app(EntryAttachments::class);

    $dot = $attachments->upload($alice, $entry->id, vaultigen_raw_upload('..', 'x'));
    expect($attachments->metadata(FileAttachment::query()->findOrFail($dot))['filename'])->toBe('attachment.bin');

    $path = $attachments->upload($alice, $entry->id, vaultigen_raw_upload('C:\\Users\\alice\\payload.exe', 'x'));
    expect($attachments->metadata(FileAttachment::query()->findOrFail($path))['filename'])->toBe('payload.exe');

    $control = $attachments->upload($alice, $entry->id, vaultigen_raw_upload("bad\x00name.txt", 'x'));
    expect($attachments->metadata(FileAttachment::query()->findOrFail($control))['filename'])->toBe('badname.txt');

    $long = $attachments->upload($alice, $entry->id, vaultigen_raw_upload(str_repeat('a', 300).'.txt', 'x'));
    expect(mb_strlen($attachments->metadata(FileAttachment::query()->findOrFail($long))['filename']))->toBe(200);
});

test('metadata falls back to safe defaults when the envelope holds unusable values', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachmentId = (string) Str::uuid();
    $versionId = app(VaultKeyManager::class)->activeVersionId($vault->id);
    $sealed = app(AttachmentCipher::class)->sealMetadata($vault->id, $attachmentId, $versionId, '{"filename":123,"mime":false}');

    DB::table('file_attachments')->insert([
        'id' => $attachmentId,
        'vault_id' => $vault->id,
        'entry_id' => $entry->id,
        'vault_key_version_id' => $versionId,
        'encrypted_metadata' => $sealed->envelope,
        'byte_size' => 1,
        'random_storage_path' => Str::uuid()->toString().'.att',
        'stream_format_version' => 1,
        'wrapped_file_key_algorithm' => 'xchacha20-poly1305',
        'wrapped_file_key_nonce' => 'nonce',
        'wrapped_file_key' => 'wrapped',
        'ciphertext_checksum' => str_repeat('0', 64),
        'state' => FileAttachment::STATE_AVAILABLE,
        'created_by_user_id' => $alice->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $metadata = app(EntryAttachments::class)->metadata(FileAttachment::query()->findOrFail($attachmentId));
    expect($metadata['filename'])->toBe('attachment.bin')
        ->and($metadata['mime'])->toBe('application/octet-stream');
});

test('upload authorization requires an editor on a live entry in an active vault', function () {
    ['admin' => $admin, 'alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachments = app(EntryAttachments::class);
    $file = fn (): UploadedFile => vaultigen_raw_upload('x.txt', 'body');

    // Bob is a Viewer.
    expect(fn () => $attachments->upload($bob, $entry->id, $file()))->toThrow(AuthorizationException::class);

    // Carol has no grant at all.
    expect(fn () => $attachments->upload($carol, $entry->id, $file()))->toThrow(NotFoundHttpException::class);

    // A trashed entry cannot receive files.
    app(VaultAdministration::class)->setState($admin, $vault->id, 'read_only');
    expect(fn () => $attachments->upload($alice, $entry->id, $file()))->toThrow(ValidationException::class);

    app(VaultAdministration::class)->setState($admin, $vault->id, 'active');
    DB::table('entries')->where('id', $entry->id)->update(['deleted_at' => now()]);
    expect(fn () => $attachments->upload($alice, $entry->id, $file()))->toThrow(NotFoundHttpException::class);
    expect(fn () => $attachments->upload($alice, (string) Str::uuid(), $file()))->toThrow(NotFoundHttpException::class);
});

test('invalid and oversized uploads fail closed, free the reservation, and are audited', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachments = app(EntryAttachments::class);

    $invalid = new UploadedFile(tempnam(sys_get_temp_dir(), 'bad-'), 'x.txt', 'text/plain', UPLOAD_ERR_INI_SIZE, true);
    expect(fn () => $attachments->upload($alice, $entry->id, $invalid))->toThrow(ValidationException::class);

    expect(fn () => $attachments->upload($alice, $entry->id, UploadedFile::fake()->createWithContent('empty.txt', '')))
        ->toThrow(ValidationException::class);

    $missingPath = tempnam(sys_get_temp_dir(), 'ghost-');
    $missing = new UploadedFile($missingPath, 'ghost.txt', 'text/plain', UPLOAD_ERR_OK, true);
    unlink($missingPath);
    expect(fn () => $attachments->upload($alice, $entry->id, $missing))->toThrow(ValidationException::class);

    config(['vaultigen.attachments.max_bytes_per_file' => 4]);
    expect(fn () => $attachments->upload($alice, $entry->id, vaultigen_raw_upload('big.txt', 'too many bytes')))
        ->toThrow(ValidationException::class);

    config(['vaultigen.attachments.max_bytes_per_file' => 100, 'vaultigen.attachments.max_bytes_per_vault' => 4]);
    expect(fn () => $attachments->upload($alice, $entry->id, vaultigen_raw_upload('quota.txt', 'too many bytes')))
        ->toThrow(ValidationException::class);

    expect(DB::table('file_attachments')->count())->toBe(0)
        ->and(DB::table('attachment_reservations')->where('state', 'reserved')->count())->toBe(0);
    $this->assertDatabaseHas('audit_events', ['action' => 'attachment.validation_failed', 'outcome' => 'denied']);
});

test('a failure while encrypting releases the reservation and leaves no partial attachment', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);

    // A destination that accepts the write handle but never accepts bytes makes
    // the stream cipher fail after both resources are open.
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('makeDirectory')->andReturnTrue();
    $disk->shouldReceive('path')->andReturn('vaultigen-failwrite://sink');
    $disk->shouldReceive('delete')->once()->andReturnTrue();
    Storage::shouldReceive('disk')->with('attachments')->andReturn($disk);

    expect(fn () => app(EntryAttachments::class)->upload($alice, $entry->id, vaultigen_raw_upload('x.txt', 'body')))
        ->toThrow(RuntimeException::class);

    expect(DB::table('file_attachments')->count())->toBe(0)
        ->and(DB::table('attachment_reservations')->where('state', 'reserved')->count())->toBe(0);
    $this->assertDatabaseHas('audit_events', ['action' => 'attachment.validation_failed', 'outcome' => 'failed']);
});

test('download authorization is viewer-scoped and rejects deleted or trashed attachments', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachment = vaultigen_upload_attachment($alice, $entry, 'body', 'x.txt');
    $attachments = app(EntryAttachments::class);

    expect($attachments->authorizeDownload($bob, $attachment->id)->id)->toBe($attachment->id);
    expect(fn () => $attachments->authorizeDownload($carol, $attachment->id))->toThrow(NotFoundHttpException::class);

    $attachments->delete($alice, $attachment->id);
    expect(fn () => $attachments->authorizeDownload($bob, $attachment->id))->toThrow(NotFoundHttpException::class);
    expect(fn () => $attachments->authorizeDownload($bob, (string) Str::uuid()))->toThrow(NotFoundHttpException::class);

    $other = vaultigen_upload_attachment($alice, $entry, 'body', 'y.txt');
    DB::table('entries')->where('id', $entry->id)->update(['deleted_at' => now()]);
    expect(fn () => $attachments->authorizeDownload($bob, $other->id))->toThrow(NotFoundHttpException::class);

    // A non-available state is not downloadable.
    DB::table('entries')->where('id', $entry->id)->update(['deleted_at' => null]);
    DB::table('file_attachments')->where('id', $other->id)->update(['state' => FileAttachment::STATE_QUARANTINED]);
    expect(fn () => $attachments->authorizeDownload($bob, $other->id))->toThrow(NotFoundHttpException::class);
});

test('streaming fails closed when the object is missing or corrupted', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachment = vaultigen_upload_attachment($alice, $entry, 'body', 'x.txt');
    $attachments = app(EntryAttachments::class);

    Storage::disk('attachments')->delete($attachment->random_storage_path);
    expect(fn () => $attachments->streamTo($attachment, vaultigen_stream_open()))->toThrow(RuntimeException::class);

    $second = vaultigen_upload_attachment($alice, $entry, 'body', 'y.txt');
    file_put_contents(Storage::disk('attachments')->path($second->random_storage_path), 'garbage');
    expect(fn () => $attachments->streamTo($second, vaultigen_stream_open()))->toThrow(RuntimeException::class);
});

test('attachment relationships resolve the vault, entry, and uploader', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachment = vaultigen_upload_attachment($alice, $entry, 'body', 'x.txt')->refresh();

    expect($attachment->vault->id)->toBe($vault->id)
        ->and($attachment->entry->id)->toBe($entry->id)
        ->and($attachment->createdBy->id)->toBe($alice->id);
});

test('delete and restore track attachment membership and the reveal trail', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $first = vaultigen_upload_attachment($alice, $entry, 'one', 'one.txt');
    $this->travel(1)->second();
    $second = vaultigen_upload_attachment($alice, $entry, 'two', 'two.txt');
    $attachments = app(EntryAttachments::class);

    expect($attachments->forEntry($entry)->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($attachments->idsForEntry($entry->id))->toBe([$first->id, $second->id]);

    expect(fn () => $attachments->delete($bob, $first->id))->toThrow(AuthorizationException::class);
    $attachments->delete($alice, $first->id);
    $this->assertDatabaseHas('file_attachments', ['id' => $first->id, 'state' => 'deleted']);
    $this->assertDatabaseHas('audit_events', ['action' => 'attachment.deleted']);
    expect($attachments->forEntry($entry)->pluck('id')->all())->toBe([$second->id]);

    // Restoring a revision with first reattaches it and detaches second.
    $attachments->restoreMembership($vault->id, $entry->id, [$first->id]);
    expect($attachments->forEntry($entry)->pluck('id')->all())->toBe([$first->id]);

    // An empty snapshot detaches everything.
    $attachments->restoreMembership($vault->id, $entry->id, []);
    expect($attachments->forEntry($entry)->count())->toBe(0);

    $attachments->restoreMembership($vault->id, $entry->id, [$second->id]);
    expect($attachments->forEntry($entry)->pluck('id')->all())->toBe([$second->id]);

    // Download tracking writes the audit event and the per-user reveal marker.
    $attachments->recordDownloadStart($bob, $second);
    $attachments->recordDownloadCompleted($bob, $second);
    $attachments->recordDownloadFailed($bob, $second);
    $this->assertDatabaseHas('audit_events', ['action' => 'attachment.download_started', 'actor_id' => $bob->id]);
    $this->assertDatabaseHas('audit_events', ['action' => 'attachment.download_completed', 'actor_id' => $bob->id]);
    $this->assertDatabaseHas('audit_events', ['action' => 'attachment.download_completed', 'outcome' => 'failed']);
    $this->assertDatabaseHas('secret_reveals', ['user_id' => $bob->id, 'entry_id' => $entry->id]);

    $usage = $attachments->usage($vault->id);
    expect($usage['used'])->toBeGreaterThan(0)
        ->and($usage['limit'])->toBeGreaterThan(0)
        ->and($usage['per_file'])->toBeGreaterThan(0);
});

test('store encrypts a memory stream with detected metadata and audits it like an upload', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $contents = '%PDF-1.4 synthetic stream bytes';

    $id = app(EntryAttachments::class)->store($alice, $entry->id, vaultigen_stream_from($contents), strlen($contents), 'scan.pdf');

    $attachment = FileAttachment::query()->findOrFail($id);
    expect(app(EntryAttachments::class)->metadata($attachment))->toBe(['filename' => 'scan.pdf', 'mime' => 'application/pdf'])
        ->and($attachment->byte_size)->toBe(strlen($contents))
        ->and(vaultigen_decrypt_attachment($attachment))->toBe($contents);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $alice->id, 'action' => 'attachment.uploaded']);
    $this->assertDatabaseHas('attachment_reservations', ['attachment_id' => $id, 'state' => 'committed']);
});

test('store enforces the same authorization as an upload', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $attachments = app(EntryAttachments::class);

    expect(fn () => $attachments->store($bob, $entry->id, vaultigen_stream_from('body'), 4, 'x.txt'))->toThrow(AuthorizationException::class)
        ->and(fn () => $attachments->store($carol, $entry->id, vaultigen_stream_from('body'), 4, 'x.txt'))->toThrow(NotFoundHttpException::class)
        ->and(DB::table('file_attachments')->count())->toBe(0);
});

test('store rejects empty, mismatched, and over-quota streams and frees the reservation', function (string $contents, int $size, array $config) {
    config($config);
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);

    expect(fn () => app(EntryAttachments::class)->store($alice, $entry->id, vaultigen_stream_from($contents), $size, 'x.txt'))
        ->toThrow(ValidationException::class);

    expect(DB::table('file_attachments')->count())->toBe(0)
        ->and(DB::table('attachment_reservations')->where('state', 'reserved')->count())->toBe(0);
    $this->assertDatabaseHas('audit_events', ['action' => 'attachment.validation_failed', 'outcome' => 'denied']);
})->with([
    'empty stream' => ['', 0, []],
    'declared size differs from the stream' => ['twelve bytes', 4, []],
    'over the vault quota' => ['too many bytes', 14, ['vaultigen.attachments.max_bytes_per_vault' => 4]],
]);
