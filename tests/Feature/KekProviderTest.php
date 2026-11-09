<?php

use App\Contracts\KekProvider;
use App\Services\Crypto\KeyringKekProvider;

function vaultigen_keyring_json(string $activeKeyId = 'k1'): string
{
    return json_encode([
        'active_key_id' => $activeKeyId,
        'keys' => [
            $activeKeyId => base64_encode(str_repeat('k', 32)),
            'k0' => base64_encode(str_repeat('o', 32)),
        ],
    ], JSON_THROW_ON_ERROR);
}

test('the keyring provider resolves keys by stable id and rejects invalid keyrings', function () {
    $provider = KeyringKekProvider::fromJson(vaultigen_keyring_json());
    expect($provider)->toBeInstanceOf(KekProvider::class)
        ->and($provider->activeKeyId())->toBe('k1')
        ->and($provider->keyIds())->toBe(['k1', 'k0'])
        ->and(strlen($provider->key('k0')))->toBe(32)
        ->and(fn () => $provider->key('missing'))->toThrow(RuntimeException::class, 'unavailable');

    expect(fn () => KeyringKekProvider::fromJson('{not json'))
        ->toThrow(RuntimeException::class, 'missing or invalid');
    expect(fn () => KeyringKekProvider::fromJson('true'))
        ->toThrow(RuntimeException::class, 'missing or invalid');
    expect(fn () => KeyringKekProvider::fromArray([]))
        ->toThrow(RuntimeException::class, 'missing or invalid');
    expect(fn () => KeyringKekProvider::fromArray(['active_key_id' => 'k1', 'keys' => []]))
        ->toThrow(RuntimeException::class, 'missing or invalid');
    expect(fn () => KeyringKekProvider::fromArray(['active_key_id' => 'k1', 'keys' => ['k1' => 'not-base64']]))
        ->toThrow(RuntimeException::class, 'invalid key');
    expect(fn () => KeyringKekProvider::fromArray(['active_key_id' => 'k1', 'keys' => ['k1' => base64_encode('short')]]))
        ->toThrow(RuntimeException::class, 'invalid key');
    expect(fn () => KeyringKekProvider::fromArray(['active_key_id' => 'k1', 'keys' => [0 => base64_encode(str_repeat('k', 32))]]))
        ->toThrow(RuntimeException::class, 'invalid key');
    expect(fn () => KeyringKekProvider::fromArray(['active_key_id' => 'other', 'keys' => ['k1' => base64_encode(str_repeat('k', 32))]]))
        ->toThrow(RuntimeException::class, 'active installation KEK');
});

test('the file and environment providers are selected by configuration', function () {
    $json = vaultigen_keyring_json();
    $path = tempnam(sys_get_temp_dir(), 'vaultigen-kek-');
    file_put_contents($path, $json);

    try {
        config(['vaultigen.kek_provider' => 'file', 'vaultigen.kek_file' => $path]);
        expect(app(KekProvider::class)->activeKeyId())->toBe('k1');

        config(['vaultigen.kek_provider' => 'environment', 'vaultigen.kek_keyring' => $json]);
        expect(app(KekProvider::class)->activeKeyId())->toBe('k1');

        config(['vaultigen.kek_provider' => 'file', 'vaultigen.kek_file' => $path.'-missing']);
        expect(fn () => app(KekProvider::class))->toThrow(RuntimeException::class, 'KEK key file');
    } finally {
        @unlink($path);
    }
});
