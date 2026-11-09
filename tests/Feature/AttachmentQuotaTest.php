<?php

use App\Services\AttachmentQuota;
use App\Services\Crypto\VaultKeyManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function vaultigen_store_attachment_row(string $vaultId, string $entryId, string $ownerId, int $bytes, string $state = 'available'): string
{
    $id = (string) Str::uuid();
    DB::table('file_attachments')->insert([
        'id' => $id,
        'vault_id' => $vaultId,
        'entry_id' => $entryId,
        'vault_key_version_id' => app(VaultKeyManager::class)->activeVersionId($vaultId),
        'encrypted_metadata' => 'envelope',
        'byte_size' => $bytes,
        'random_storage_path' => Str::uuid()->toString().'.att',
        'stream_format_version' => 1,
        'wrapped_file_key_algorithm' => 'xchacha20-poly1305',
        'wrapped_file_key_nonce' => 'nonce',
        'wrapped_file_key' => 'wrapped',
        'ciphertext_checksum' => str_repeat('a', 64),
        'state' => $state,
        'created_by_user_id' => $ownerId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('reservations reconcile stored and reserved bytes against the vault limit', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    config([
        'vaultigen.attachments.max_bytes_per_file' => 1000,
        'vaultigen.attachments.max_bytes_per_vault' => 2500,
    ]);
    $quota = app(AttachmentQuota::class);

    $first = $quota->reserve($alice, $vault->id, 1000);
    expect($quota->usedBytes($vault->id))->toBe(1000);

    // Committing the reservation replaces it with the stored attachment row.
    $attachmentId = vaultigen_store_attachment_row($vault->id, $entry->id, $alice->id, 1000);
    $quota->commit($first, $attachmentId);
    expect($quota->usedBytes($vault->id))->toBe(1000)
        ->and(DB::table('attachment_reservations')->where('id', $first)->value('state'))->toBe('committed');

    $second = $quota->reserve($alice, $vault->id, 1000);
    expect($quota->usedBytes($vault->id))->toBe(2000);

    expect(fn () => $quota->reserve($alice, $vault->id, 1000))->toThrow(ValidationException::class);

    $quota->release($second);
    expect($quota->usedBytes($vault->id))->toBe(1000)
        ->and(DB::table('attachment_reservations')->where('id', $second)->value('state'))->toBe('released');

    // Releasing an already-released reservation is idempotent.
    $quota->release($second);

    expect(fn () => $quota->reserve($alice, $vault->id, 1001))->toThrow(ValidationException::class);
    expect(fn () => $quota->reserve($alice, $vault->id, 0))->toThrow(ValidationException::class);
});

test('expired reservations are released idempotently and free the quota', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    config([
        'vaultigen.attachments.max_bytes_per_file' => 1000,
        'vaultigen.attachments.max_bytes_per_vault' => 1000,
    ]);
    $quota = app(AttachmentQuota::class);

    $reservation = $quota->reserve($alice, $vault->id, 1000);
    expect(fn () => $quota->reserve($alice, $vault->id, 1))->toThrow(ValidationException::class);

    DB::table('attachment_reservations')->where('id', $reservation)->update(['expires_at' => now()->subMinute()]);

    expect($quota->releaseExpired())->toBe(1)
        ->and($quota->releaseExpired())->toBe(0)
        ->and($quota->usedBytes($vault->id))->toBe(0);

    // A new reservation succeeds after the expired one is reaped.
    $quota->reserve($alice, $vault->id, 1000);
    expect($quota->usedBytes($vault->id))->toBe(1000);
});

test('deleted attachments free their quota', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    config(['vaultigen.attachments.max_bytes_per_vault' => 1000]);
    $quota = app(AttachmentQuota::class);

    $attachmentId = vaultigen_store_attachment_row($vault->id, $entry->id, $alice->id, 1000);
    expect($quota->usedBytes($vault->id))->toBe(1000);

    DB::table('file_attachments')->where('id', $attachmentId)->update(['deleted_at' => now(), 'state' => 'deleted']);

    expect($quota->usedBytes($vault->id))->toBe(0);
});
