<?php

namespace App\Services\Licensing;

use RuntimeException;

/**
 * A license failed verification. The reason is a machine-readable code; the
 * license text is never included.
 */
final class InvalidLicense extends RuntimeException
{
    public const REASONS = ['malformed', 'unknown_key', 'bad_signature', 'unsupported_version', 'wrong_product', 'schema'];

    public function __construct(public readonly string $reason)
    {
        parent::__construct('The license failed verification: '.$reason);
    }
}
