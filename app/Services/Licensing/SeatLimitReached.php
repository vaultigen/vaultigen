<?php

namespace App\Services\Licensing;

use Illuminate\Validation\ValidationException;

/**
 * Adding a user would exceed the seat limit of the current license state.
 *
 * It is a validation error so forms show the message next to the field;
 * the attributes feed the "seat_limit.reached" audit event.
 */
final class SeatLimitReached extends ValidationException
{
    public string $context = '';

    public int $inUse = 0;

    public int $limit = 0;

    public static function make(string $context, int $inUse, int $limit, string $field, string $message): self
    {
        $exception = self::withMessages([$field => $message]);
        $exception->context = $context;
        $exception->inUse = $inUse;
        $exception->limit = $limit;

        return $exception;
    }
}
