<?php

namespace App\Services\Imports;

use RuntimeException;

/**
 * Thrown when a running import observes a cancellation request. The runner
 * catches it, finalizes the run as cancelled, and purges the staging object.
 */
final class ImportCancelledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The import run was cancelled.');
    }
}
