<?php

namespace App\Services\Imports\Keepass;

use RuntimeException;

/**
 * A KeePass file that cannot be staged, with a reason the hub can explain.
 */
final class KeepassImportException extends RuntimeException
{
    public const NOT_KEEPASS = 'unreadable_keepass';

    public const PROTECTED_VALUES = 'protected_values';

    public const ATTACHMENTS_TOO_LARGE = 'attachments_too_large';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('The KeePass file cannot be imported: '.$reason.'.');
    }
}
