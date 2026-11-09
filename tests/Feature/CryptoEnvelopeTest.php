<?php

use App\Services\Crypto\EnvelopeCipher;

test('associated data encoding is canonical and length prefixed', function () {
    $encoded = vaultigen_associated(['purpose' => 'entry-payload', 'vault_id' => 'v']);
    expect($encoded)->toBe(vaultigen_associated(['vault_id' => 'v', 'purpose' => 'entry-payload']))
        ->and($encoded)->toContain(pack('N', 7).'purpose'.pack('N', 13).'entry-payload')
        ->and($encoded)->not->toBe(vaultigen_associated(['purpose' => 'x', 'vault_id' => 'y']));
});

test('envelopes round trip and capture version algorithm purpose and key id', function () {
    $cipher = new EnvelopeCipher;
    $key = random_bytes(32);
    $associated = vaultigen_associated(['scope' => 'unit']);
    $sealed = $cipher->seal(EnvelopeCipher::PURPOSE_IMPORT_SOURCE, 'kek-test-v1', $key, 'synthetic secret', $associated);

    $binary = vaultigen_base64url_decode($sealed->envelope);
    expect(ord($binary[0]))->toBe(EnvelopeCipher::FORMAT_VERSION)
        ->and(ord($binary[1]))->toBe(1)
        ->and(ord($binary[2]))->toBe(4)
        ->and(strlen(vaultigen_base64url_decode($sealed->nonce)))->toBe(24)
        ->and($cipher->open(EnvelopeCipher::PURPOSE_IMPORT_SOURCE, 'kek-test-v1', $key, $sealed->envelope, $associated))->toBe('synthetic secret');
});

test('envelope decryption fails closed on the wrong key key id purpose aad or ciphertext', function () {
    $cipher = new EnvelopeCipher;
    $key = random_bytes(32);
    $associated = vaultigen_associated(['scope' => 'unit']);
    $sealed = $cipher->seal(EnvelopeCipher::PURPOSE_ENTRY_PAYLOAD, 'kek-test-v1', $key, 'synthetic secret', $associated);

    expect(fn () => $cipher->open(EnvelopeCipher::PURPOSE_ENTRY_PAYLOAD, 'kek-test-v1', random_bytes(32), $sealed->envelope, $associated))
        ->toThrow(RuntimeException::class, 'Unable to decrypt');
    expect(fn () => $cipher->open(EnvelopeCipher::PURPOSE_ENTRY_PAYLOAD, 'other-key', $key, $sealed->envelope, $associated))
        ->toThrow(RuntimeException::class, 'encryption-key ID');
    expect(fn () => $cipher->open(EnvelopeCipher::PURPOSE_VAULT_KEY_WRAP, 'kek-test-v1', $key, $sealed->envelope, $associated))
        ->toThrow(RuntimeException::class, 'purpose');
    expect(fn () => $cipher->open(EnvelopeCipher::PURPOSE_ENTRY_PAYLOAD, 'kek-test-v1', $key, $sealed->envelope, vaultigen_associated(['scope' => 'other'])))
        ->toThrow(RuntimeException::class, 'Unable to decrypt');

    $tampered = vaultigen_base64url_encode(vaultigen_flip_byte(vaultigen_base64url_decode($sealed->envelope), -1));
    expect(fn () => $cipher->open(EnvelopeCipher::PURPOSE_ENTRY_PAYLOAD, 'kek-test-v1', $key, $tampered, $associated))
        ->toThrow(RuntimeException::class, 'Unable to decrypt');
});

test('envelope parsing rejects malformed and unsupported envelopes', function () {
    $cipher = new EnvelopeCipher;
    $key = random_bytes(32);
    $sealed = $cipher->seal(EnvelopeCipher::PURPOSE_FILE_KEY_WRAP, 'kek-test-v1', $key, 'synthetic secret', '');

    expect(fn () => $cipher->open(EnvelopeCipher::PURPOSE_FILE_KEY_WRAP, 'kek-test-v1', $key, '', ''))
        ->toThrow(RuntimeException::class, 'malformed');
    expect(fn () => $cipher->open(EnvelopeCipher::PURPOSE_FILE_KEY_WRAP, 'kek-test-v1', $key, '!!!!not-base64!!!!', ''))
        ->toThrow(RuntimeException::class, 'malformed');
    expect(fn () => $cipher->open(EnvelopeCipher::PURPOSE_FILE_KEY_WRAP, 'kek-test-v1', $key, vaultigen_base64url_encode('short'), ''))
        ->toThrow(RuntimeException::class, 'malformed');

    $binary = vaultigen_base64url_decode($sealed->envelope);
    expect(fn () => $cipher->open(EnvelopeCipher::PURPOSE_FILE_KEY_WRAP, 'kek-test-v1', $key, vaultigen_base64url_encode(vaultigen_flip_byte($binary, 0)), ''))
        ->toThrow(RuntimeException::class, 'format version');
    expect(fn () => $cipher->open(EnvelopeCipher::PURPOSE_FILE_KEY_WRAP, 'kek-test-v1', $key, vaultigen_base64url_encode(vaultigen_flip_byte($binary, 1)), ''))
        ->toThrow(RuntimeException::class, 'algorithm');
    expect(fn () => $cipher->open(EnvelopeCipher::PURPOSE_FILE_KEY_WRAP, 'kek-test-v1', $key, vaultigen_base64url_encode(vaultigen_flip_byte($binary, 2)), ''))
        ->toThrow(RuntimeException::class, 'purpose is not supported');

    $oversizedHeader = chr(1).chr(1).chr(1).chr(0).pack('n', 200).'short';
    expect(fn () => $cipher->open(EnvelopeCipher::PURPOSE_VAULT_KEY_WRAP, 'kek-test-v1', $key, vaultigen_base64url_encode($oversizedHeader), ''))
        ->toThrow(RuntimeException::class, 'malformed');

    $shortKeyId = chr(1).chr(1).chr(1).chr(0).pack('n', 20).'short'.str_repeat('a', 40);
    expect(fn () => $cipher->open(EnvelopeCipher::PURPOSE_VAULT_KEY_WRAP, 'kek-test-v1', $key, vaultigen_base64url_encode($shortKeyId), ''))
        ->toThrow(RuntimeException::class, 'malformed');

    $fileKey = $cipher->seal(EnvelopeCipher::PURPOSE_FILE_KEY_WRAP, 'kek-test-v1', $key, 'wrapped file key', '');
    expect($cipher->open(EnvelopeCipher::PURPOSE_FILE_KEY_WRAP, 'kek-test-v1', $key, $fileKey->envelope, ''))->toBe('wrapped file key');

    expect(fn () => $cipher->seal('bogus-purpose', 'kek-test-v1', $key, 'x', ''))
        ->toThrow(InvalidArgumentException::class, 'Unknown envelope purpose');
    expect(fn () => $cipher->seal(EnvelopeCipher::PURPOSE_ENTRY_PAYLOAD, 'kek-test-v1', 'too-short', 'x', ''))
        ->toThrow(InvalidArgumentException::class, 'must be 32 bytes');
    expect(fn () => $cipher->open(EnvelopeCipher::PURPOSE_ENTRY_PAYLOAD, 'kek-test-v1', 'too-short', $sealed->envelope, ''))
        ->toThrow(InvalidArgumentException::class, 'must be 32 bytes');
});
