<?php

namespace App\Services\Operations;

/**
 * Path checks for private operational storage.
 *
 * Roots are operator-configured and trusted, so links above or at a root
 * (release symlinks, shared storage, bind mounts) are resolved rather than
 * refused. Paths below a root come from backup manifests or database rows and
 * must not traverse out of it or pass through links.
 */
final class PrivatePaths
{
    public static function relative(string $path): void
    {
        if ($path === '' || preg_match('~^[A-Za-z0-9_-][A-Za-z0-9_./-]*$~D', $path) !== 1
            || array_intersect(explode('/', $path), ['', '.', '..']) !== []) {
            throw new OperationFailedException('unsafe_path');
        }
    }

    /**
     * Canonicalize a trusted absolute root. Missing trailing components are
     * kept so a root can be created later.
     */
    public static function root(string $path): string
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            throw new OperationFailedException('unsafe_path');
        }
        $existing = rtrim($path, '/') ?: '/';
        $missing = [];
        while (($resolved = realpath($existing)) === false) {
            array_unshift($missing, basename($existing));
            $existing = dirname($existing);
        }

        return rtrim($resolved.'/'.implode('/', $missing), '/') ?: '/';
    }

    public static function within(string $root, string $relative): string
    {
        self::relative($relative);
        $root = self::root($root);
        $path = rtrim($root, '/').'/'.$relative;
        for ($part = $path; strlen($part) > strlen($root); $part = dirname($part)) {
            self::assertNotLinked($part);
        }

        return $path;
    }

    /**
     * A single file in a trusted directory, such as a lock file or the SQLite
     * database. Only the file itself must not be a link.
     */
    public static function file(string $path): string
    {
        if (in_array(basename($path), ['', '.', '..'], true)) {
            throw new OperationFailedException('unsafe_path');
        }
        $path = rtrim(self::root(dirname($path)), '/').'/'.basename($path);
        self::assertNotLinked($path);

        return $path;
    }

    public static function directory(string $path): void
    {
        $path = self::root($path);
        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new OperationFailedException('object_unwritable');
        }
    }

    public static function space(string $path, int $bytes): void
    {
        $parent = $path;
        while (! is_dir($parent) && dirname($parent) !== $parent) {
            $parent = dirname($parent);
        }
        $free = @disk_free_space($parent);
        if ($free === false || $free < $bytes + max(0, (int) config('vaultigen.storage.min_free_bytes'))) {
            throw new OperationFailedException('insufficient_space');
        }
    }

    private static function assertNotLinked(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || (is_file($path) && (int) (stat($path)['nlink'] ?? 0) > 1)) {
            throw new OperationFailedException('unsafe_path');
        }
    }
}
