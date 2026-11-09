<?php

namespace App\Services\Operations;

use App\Services\Crypto\KeyringKekProvider;
use Throwable;

/**
 * Resolves the backup encryption keyring.
 *
 * The backup key is deliberately separate from the live installation KEK. It
 * protects every data-backup set, and it must be escrowed independently so a
 * stolen live key cannot read old restore points and a stolen backup is useless
 * without it. The keyring reuses the same file/environment format as the KEK.
 */
final class BackupKeyring
{
    private ?KeyringKekProvider $keyring = null;

    public function provider(): KeyringKekProvider
    {
        if ($this->keyring !== null) {
            return $this->keyring;
        }

        $inline = config('vaultigen.backup_keyring');

        return $this->keyring = is_string($inline) && $inline !== ''
            ? KeyringKekProvider::fromJson($inline)
            : KeyringKekProvider::fromFile((string) config('vaultigen.backup_key_file'));
    }

    /**
     * Whether a usable backup keyring is configured. The Operations page shows
     * this readiness flag instead of throwing a configuration error.
     */
    public function available(): bool
    {
        try {
            $this->provider();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function activeKeyId(): string
    {
        return $this->provider()->activeKeyId();
    }

    /**
     * @return list<string>
     */
    public function keyIds(): array
    {
        return $this->provider()->keyIds();
    }

    public function key(string $keyId): string
    {
        return $this->provider()->key($keyId);
    }
}
