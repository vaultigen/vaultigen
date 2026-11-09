<?php

namespace App\Services\Imports;

use App\Contracts\EntrySecretResolver;

/**
 * The resolver for sources whose sealed staging already holds plaintext.
 */
final class PassThroughSecretResolver implements EntrySecretResolver
{
    public function verify(array $entries, #[\SensitiveParameter] array $credentials): ?string
    {
        return null;
    }

    public function resolve(array $entry, #[\SensitiveParameter] array $credentials): array
    {
        return ['password' => is_string($entry['password'] ?? null) ? $entry['password'] : ''];
    }
}
