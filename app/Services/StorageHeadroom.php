<?php

namespace App\Services;

/**
 * Measures free space on the volume that holds the database, attachments, and
 * backup sets so the Installation status page can warn before the disk fills.
 *
 * It deliberately reports a hint instead of enforcing a hard stop: a full disk
 * already produces ordinary write errors, and refusing to serve the admin UI
 * because a threshold was crossed would make the problem harder to diagnose.
 */
final class StorageHeadroom
{
    /**
     * Free bytes on the given directory, or null when the path is missing or
     * the operating system cannot report a value.
     */
    public function freeBytes(string $path): ?int
    {
        if (! is_dir($path)) {
            return null;
        }

        $free = @disk_free_space($path);

        return is_float($free) ? (int) $free : null;
    }

    /**
     * Whether the directory exists and has less free space than the minimum.
     * An unknown directory is not treated as low, because the check is only a
     * warning and a false alarm trains operators to ignore it.
     */
    public function belowMinimum(string $path, int $minimumBytes): bool
    {
        $free = $this->freeBytes($path);

        return $free !== null && $free < $minimumBytes;
    }
}
