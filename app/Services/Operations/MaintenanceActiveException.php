<?php

namespace App\Services\Operations;

use RuntimeException;

/**
 * Thrown when a write is attempted while the installation is quiesced for a
 * backup. The message is deliberately generic so it is safe to surface in an
 * HTTP response or a log line.
 */
final class MaintenanceActiveException extends RuntimeException
{
    public function __construct(public readonly ?string $operation)
    {
        parent::__construct('The installation is in a maintenance window; writes are paused.');
    }
}
