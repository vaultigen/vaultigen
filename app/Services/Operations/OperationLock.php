<?php

namespace App\Services\Operations;

/**
 * Exclusive, process-scoped lock for backup, retention, backup-key rotation,
 * and restore. The operating system releases it when the owning process dies.
 *
 * The lock is reentrant within its owning process: a queue timeout runs the
 * job's failure callback in the same process that still holds the lock, and
 * that callback must be able to fail the run instead of treating its own
 * process as a live competitor.
 */
final class OperationLock
{
    /** @var resource|null */
    private static $handle = null;

    private static int $depth = 0;

    private bool $held = false;

    public static function restoreMarker(): string
    {
        return storage_path('framework/restore-in-progress.json');
    }

    public function acquire(bool $allowRestore = false): void
    {
        if (! $allowRestore && is_file(self::restoreMarker())) {
            throw new OperationFailedException('restore_apply_failed');
        }
        if ($this->held) {
            return;
        }
        if (self::$depth === 0) {
            $path = PrivatePaths::file(storage_path('framework/backup-operations.lock'));
            $handle = @fopen($path, 'c');
            if ($handle === false) {
                throw new OperationFailedException('object_unwritable');
            }
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                throw new OperationFailedException('backup_in_progress');
            }
            self::$handle = $handle;
            chmod($path, 0600);
        }
        self::$depth++;
        $this->held = true;
    }

    public function release(): void
    {
        if (! $this->held) {
            return;
        }
        $this->held = false;
        self::$depth--;
        if (self::$depth === 0 && is_resource(self::$handle)) {
            flock(self::$handle, LOCK_UN);
            fclose(self::$handle);
            self::$handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
