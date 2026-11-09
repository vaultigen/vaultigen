<?php

namespace App\Services\Crypto;

use App\Contracts\KekProvider;
use JsonException;
use RuntimeException;

final class KeyringKekProvider implements KekProvider
{
    /**
     * @param  array<string, string>  $keys
     */
    private function __construct(
        private readonly string $activeKeyId,
        private readonly array $keys,
    ) {}

    /**
     * @param  array<string, mixed>  $keyring
     */
    public static function fromArray(array $keyring): self
    {
        $activeKeyId = $keyring['active_key_id'] ?? null;
        $keys = $keyring['keys'] ?? null;

        if (! is_string($activeKeyId) || $activeKeyId === '' || ! is_array($keys) || $keys === []) {
            throw new RuntimeException('The installation KEK keyring is missing or invalid.');
        }

        $validated = [];
        foreach ($keys as $keyId => $encoded) {
            $key = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (! is_string($keyId) || $keyId === '' || $key === false
                || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new RuntimeException('The installation KEK keyring contains an invalid key.');
            }
            $validated[$keyId] = $key;
        }

        if (! array_key_exists($activeKeyId, $validated)) {
            throw new RuntimeException('The active installation KEK is missing from the keyring.');
        }

        return new self($activeKeyId, $validated);
    }

    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The installation KEK keyring is missing or invalid.');
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('The installation KEK keyring is missing or invalid.');
        }

        return self::fromArray($decoded);
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The installation KEK key file is missing or unreadable.');
        }

        return self::fromJson((string) file_get_contents($path));
    }

    public function activeKeyId(): string
    {
        return $this->activeKeyId;
    }

    public function keyIds(): array
    {
        return array_keys($this->keys);
    }

    public function key(string $keyId): string
    {
        return $this->keys[$keyId] ?? throw new RuntimeException("The installation KEK [{$keyId}] is unavailable.");
    }
}
