<?php

namespace App\Contracts;

/**
 * Turns a canonical import entry into its secret fields at write time.
 *
 * Sources whose staged representation already holds plaintext use a
 * pass-through resolver. sysPass keeps account passwords encrypted under the
 * installation master password until each entry is written.
 */
interface EntrySecretResolver
{
    /**
     * Check the execution credentials before any write.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, string>  $credentials
     * @return string|null an error code that fails the run, or null to proceed
     */
    public function verify(array $entries, #[\SensitiveParameter] array $credentials): ?string;

    /**
     * The secret fields of one entry.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, string>  $credentials
     * @return array{password: string}
     *
     * @throws \Throwable when the entry's secret cannot be recovered
     */
    public function resolve(array $entry, #[\SensitiveParameter] array $credentials): array;
}
