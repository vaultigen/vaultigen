<?php

namespace App\Services\Operations;

use RuntimeException;
use Throwable;

/**
 * A domain failure with a stable, non-secret code safe to store on an operation
 * row and show in the Operations page.
 */
final class OperationFailedException extends RuntimeException
{
    public function __construct(public readonly string $failureCode, string $message = 'The operation failed.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
