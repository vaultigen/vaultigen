<?php

namespace App\Services\Imports\Syspass;

use App\Contracts\EntrySecretResolver;
use RuntimeException;
use Throwable;

/**
 * Decrypts sysPass account passwords with the installation master password.
 *
 * Each canonical entry keeps its raw `<pass>` and `<key>` in `secret_ref`.
 * Every account key costs a 100k-iteration PBKDF2, so passwords are decrypted
 * lazily as each entry is written instead of upfront, and the master password
 * is checked against a small sample before any write.
 */
final class SyspassSecretResolver implements EntrySecretResolver
{
    public const ERROR_MASTER_PASSWORD = 'master_password_rejected';

    /**
     * How many password-protected account keys to try before deciding the
     * master password is wrong.
     */
    private const MASTER_PASSWORD_SAMPLE = 5;

    public function __construct(private readonly ExportDecryptor $decryptor) {}

    /**
     * A plaintext account counts as a success, matching the per-entry
     * behavior; a correct password is accepted as soon as the first sampled
     * account unlocks, so the common case costs a single PBKDF2. Only an
     * export where the first sampled accounts all fail is rejected.
     */
    public function verify(array $entries, #[\SensitiveParameter] array $credentials): ?string
    {
        $sampled = 0;
        foreach ($entries as $entry) {
            $sampled++;
            try {
                $this->resolve($entry, $credentials);

                return null;
            } catch (Throwable) {
                if ($sampled >= self::MASTER_PASSWORD_SAMPLE) {
                    break;
                }
            }
        }

        return $sampled === 0 ? null : self::ERROR_MASTER_PASSWORD;
    }

    public function resolve(array $entry, #[\SensitiveParameter] array $credentials): array
    {
        $masterPassword = is_string($credentials['master_password'] ?? null) ? $credentials['master_password'] : '';
        $key = is_string($entry['secret_ref']['key'] ?? null) ? (string) $entry['secret_ref']['key'] : '';
        $pass = is_string($entry['secret_ref']['pass'] ?? null) ? (string) $entry['secret_ref']['pass'] : '';

        if ($this->decryptor->isEncryptedAccountKey($key)) {
            return ['password' => $this->decryptor->decryptAccountPassword($key, $pass, $masterPassword)];
        }
        if ($this->decryptor->isEncryptedPass($pass)) {
            throw new RuntimeException('The account password is encrypted but its key is missing.');
        }

        return ['password' => $pass];
    }
}
