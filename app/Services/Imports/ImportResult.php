<?php

namespace App\Services\Imports;

/**
 * The non-secret outcome of one import run.
 *
 * Counters, failures, and the error code are safe to persist on the run and to
 * render in the report: no field value, ciphertext, complete URL, note, key, or
 * attachment filename ever reaches this object.
 *
 * A failure has a `kind`: `entry` (the entry was not written) or `attachment`
 * (the entry was written, but one of its files was not; it carries the entry
 * id and the file's 1-based position). `context` is a non-secret label that
 * tells identically titled entries apart, such as the sysPass client or the
 * KeePass group path. Runs from before `kind` existed store `client` instead.
 *
 * @phpstan-type Counters array{vaults: int, folders: int, tags: int, entries: int, grants: int, attachments: int, attachment_failures: int}
 * @phpstan-type Failure array{kind: string, source_id: string|null, title: string, context: string, error_code: string, entry_id?: string, index?: int, of?: int}
 */
final class ImportResult
{
    /**
     * @param  Counters  $counters
     * @param  list<Failure>  $failures
     */
    public function __construct(
        public readonly string $status,
        public readonly array $counters,
        public readonly array $failures,
        public readonly int $failureCount,
        public readonly ?string $errorCode = null,
    ) {}

    /**
     * @return Counters
     */
    public static function emptyCounters(): array
    {
        return ['vaults' => 0, 'folders' => 0, 'tags' => 0, 'entries' => 0, 'grants' => 0, 'attachments' => 0, 'attachment_failures' => 0];
    }

    /**
     * @param  Counters  $counters
     * @param  list<Failure>  $failures
     */
    public static function completed(array $counters, array $failures = [], ?int $failureCount = null): self
    {
        return new self('completed', $counters, $failures, $failureCount ?? count($failures));
    }

    public static function failed(string $errorCode): self
    {
        return new self('failed', self::emptyCounters(), [], 0, $errorCode);
    }

    /**
     * @param  Counters  $counters
     * @param  list<Failure>  $failures
     */
    public static function cancelled(array $counters, array $failures = [], ?int $failureCount = null): self
    {
        return new self('cancelled', $counters, $failures, $failureCount ?? count($failures));
    }

    public function succeeded(): bool
    {
        return $this->status === 'completed';
    }
}
