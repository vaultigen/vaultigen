<?php

namespace App\Contracts;

/**
 * Resolves installation key-encryption keys by stable key ID.
 *
 * The keyring is independent from APP_KEY, passkey secrets, audit-integrity
 * keys, and database credentials, and is never stored in the application
 * database.
 */
interface KekProvider
{
    /**
     * The stable key ID used for new wrappings.
     */
    public function activeKeyId(): string;

    /**
     * Every key ID currently available for unwrapping.
     *
     * @return list<string>
     */
    public function keyIds(): array;

    /**
     * Resolve a raw 32-byte key by its stable key ID.
     *
     * @throws \RuntimeException when the key is missing or invalid.
     */
    public function key(string $keyId): string;
}
