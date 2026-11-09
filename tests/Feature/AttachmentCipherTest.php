<?php

use App\Services\Crypto\AttachmentCipher;
use App\Services\Crypto\FileStreamCipher;
use App\Services\Crypto\VaultKeyManager;
use Illuminate\Support\Str;
use RuntimeException;

test('the secretstream round-trips payloads across chunk boundaries with a checksum', function () {
    $cipher = app(AttachmentCipher::class);
    $attachmentId = (string) Str::uuid();
    $key = $cipher->newFileKey();

    expect(strlen($key))->toBe(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);

    $sizes = [
        '',
        'tiny',
        str_repeat('A', FileStreamCipher::CHUNK_BYTES),
        str_repeat('B', FileStreamCipher::CHUNK_BYTES * 2 + 7),
    ];

    foreach ($sizes as $plaintext) {
        $source = vaultigen_stream_from($plaintext);
        $destination = vaultigen_stream_open();
        $result = $cipher->encryptStream($source, $destination, $key, $attachmentId);
        fclose($source);

        $ciphertext = vaultigen_stream_contents($destination);

        expect($result['plaintext_bytes'])->toBe(strlen($plaintext))
            ->and($result['ciphertext_bytes'])->toBe(strlen($ciphertext))
            ->and($result['checksum'])->toBe(hash('sha256', $ciphertext));

        $encrypted = vaultigen_stream_from($ciphertext);
        $plain = vaultigen_stream_open();
        expect($cipher->decryptStream($encrypted, $plain, $key, $attachmentId))->toBe(strlen($plaintext));
        fclose($encrypted);
        expect(vaultigen_stream_contents($plain))->toBe($plaintext);

        $forChecksum = vaultigen_stream_from($ciphertext);
        expect($cipher->verifyChecksum($forChecksum, $result['checksum']))->toBeTrue();
        fclose($forChecksum);

        $tampered = vaultigen_stream_from($ciphertext);
        expect($cipher->verifyChecksum($tampered, str_repeat('0', 64)))->toBeFalse();
        fclose($tampered);
    }
});

test('the secretstream rejects a wrong key, wrong attachment, tamper, truncation, reorder, and trailing data', function () {
    $cipher = app(AttachmentCipher::class);
    $attachmentId = (string) Str::uuid();
    $key = $cipher->newFileKey();
    $plaintext = str_repeat('Z', FileStreamCipher::CHUNK_BYTES + 100);

    $source = vaultigen_stream_from($plaintext);
    $destination = vaultigen_stream_open();
    $cipher->encryptStream($source, $destination, $key, $attachmentId);
    $ciphertext = vaultigen_stream_contents($destination);
    fclose($source);

    $frame = FileStreamCipher::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
    $header = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;

    $decrypt = function (string $bytes, string $useKey, string $useId): string {
        $source = vaultigen_stream_from($bytes);
        $output = vaultigen_stream_open();
        app(AttachmentCipher::class)->decryptStream($source, $output, $useKey, $useId);
        fclose($source);

        return vaultigen_stream_contents($output);
    };

    expect(fn () => $decrypt($ciphertext, $cipher->newFileKey(), $attachmentId))->toThrow(RuntimeException::class);
    expect(fn () => $decrypt($ciphertext, $key, (string) Str::uuid()))->toThrow(RuntimeException::class);

    $tampered = $ciphertext;
    $tampered[$header + 3] = chr(ord($tampered[$header + 3]) ^ 0xFF);
    expect(fn () => $decrypt($tampered, $key, $attachmentId))->toThrow(RuntimeException::class);

    // Truncating the final frame fails authentication.
    expect(fn () => $decrypt(substr($ciphertext, 0, -1), $key, $attachmentId))->toThrow(RuntimeException::class);

    // Dropping the final frame entirely is detected as truncation.
    expect(fn () => $decrypt(substr($ciphertext, 0, $header + $frame), $key, $attachmentId))->toThrow(RuntimeException::class);

    // Swapping two frames is detected through the per-chunk associated data.
    $reordered = substr($ciphertext, 0, $header).substr($ciphertext, $header + $frame, $frame).substr($ciphertext, $header, $frame).substr($ciphertext, $header + $frame * 2);
    expect(fn () => $decrypt($reordered, $key, $attachmentId))->toThrow(RuntimeException::class);

    expect(fn () => $decrypt($ciphertext.'x', $key, $attachmentId))->toThrow(RuntimeException::class);
    expect(fn () => $decrypt('', $key, $attachmentId))->toThrow(RuntimeException::class);

    // Trailing data after a full final frame is detected explicitly, rather
    // than folded into the final frame.
    $fullSource = vaultigen_stream_from(str_repeat('Q', FileStreamCipher::CHUNK_BYTES * 2));
    $fullDestination = vaultigen_stream_open();
    $cipher->encryptStream($fullSource, $fullDestination, $key, $attachmentId);
    fclose($fullSource);
    $full = vaultigen_stream_contents($fullDestination);
    expect(fn () => $decrypt($full.'x', $key, $attachmentId))->toThrow(RuntimeException::class);
});

test('the stream cipher fails closed on unreadable or unwritable streams', function () {
    $cipher = app(AttachmentCipher::class);
    $key = $cipher->newFileKey();
    $attachmentId = (string) Str::uuid();

    $readFailure = fopen('vaultigen-failread://x', 'r');
    expect(fn () => $cipher->encryptStream($readFailure, vaultigen_stream_open(), $key, $attachmentId))
        ->toThrow(RuntimeException::class);
    fclose($readFailure);

    expect(fn () => $cipher->decryptStream(fopen('vaultigen-failread://y', 'r'), vaultigen_stream_open(), $key, $attachmentId))
        ->toThrow(RuntimeException::class);

    $writeFailure = fopen('vaultigen-failwrite://x', 'w');
    expect(fn () => $cipher->encryptStream(vaultigen_stream_from('data'), $writeFailure, $key, $attachmentId))
        ->toThrow(RuntimeException::class);
    fclose($writeFailure);

    $checksumFailure = fopen('vaultigen-failread://z', 'r');
    expect(fn () => $cipher->verifyChecksum($checksumFailure, str_repeat('0', 64)))->toThrow(RuntimeException::class);
    fclose($checksumFailure);

    expect(fn () => $cipher->encryptStream(vaultigen_stream_from('data'), vaultigen_stream_open(), str_repeat('k', 16), $attachmentId))
        ->toThrow(InvalidArgumentException::class);
});

test('attachment metadata seals under the vault key and rejects mismatches', function () {
    ['admin' => $admin, 'alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $otherVault = vaultigen_make_vault($admin, $alice, ['name' => 'Other']);
    $keys = app(VaultKeyManager::class);
    $cipher = app(AttachmentCipher::class);

    $attachmentId = (string) Str::uuid();
    $versionId = $keys->activeVersionId($vault->id);
    $sealed = $cipher->sealMetadata($vault->id, $attachmentId, $versionId, '{"filename":"a.txt","mime":"text/plain"}');

    expect($cipher->openMetadata($vault->id, $attachmentId, $versionId, $sealed->envelope))
        ->toBe('{"filename":"a.txt","mime":"text/plain"}');

    expect(fn () => $cipher->openMetadata($vault->id, (string) Str::uuid(), $versionId, $sealed->envelope))
        ->toThrow(RuntimeException::class);
    expect(fn () => $cipher->openMetadata($otherVault->id, $attachmentId, $versionId, $sealed->envelope))
        ->toThrow(RuntimeException::class);
    expect(fn () => $cipher->openMetadata($vault->id, $attachmentId, (string) Str::uuid(), $sealed->envelope))
        ->toThrow(RuntimeException::class);
});

test('the per-file key wraps under the vault key and rejects a wrong attachment', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $keys = app(VaultKeyManager::class);
    $cipher = app(AttachmentCipher::class);

    $attachmentId = (string) Str::uuid();
    $versionId = $keys->activeVersionId($vault->id);
    $fileKey = $cipher->newFileKey();
    $wrapped = $cipher->wrapFileKey($vault->id, $attachmentId, $versionId, $fileKey);

    expect($wrapped->nonce)->not->toBe('')
        ->and($cipher->unwrapFileKey($vault->id, $attachmentId, $versionId, $wrapped->envelope))->toBe($fileKey);

    expect(fn () => $cipher->unwrapFileKey($vault->id, (string) Str::uuid(), $versionId, $wrapped->envelope))
        ->toThrow(RuntimeException::class);
});
