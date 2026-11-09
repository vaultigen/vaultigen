<?php

namespace App\Services\SiteIcons;

/**
 * Resolves an href or Location header against the URL it was found on.
 *
 * Only http(s) results are returned; data:, javascript:, and every other
 * scheme resolve to null. Fragments are dropped.
 */
final class UrlResolver
{
    public static function resolve(?string $reference, string $base): ?string
    {
        $reference = trim((string) $reference);
        if ($reference === '' || preg_match('/[\x00-\x1f\x7f\s]/', $reference) === 1) {
            return null;
        }
        $reference = explode('#', $reference, 2)[0];

        $baseParts = parse_url($base);
        if (! is_array($baseParts) || ! isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }
        $scheme = strtolower($baseParts['scheme']);

        if (str_starts_with($reference, '//')) {
            return self::httpOnly($scheme.':'.$reference);
        }

        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $reference) === 1) {
            return self::httpOnly($reference);
        }

        $origin = $scheme.'://'.$baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');

        if (str_starts_with($reference, '/')) {
            return $origin.$reference;
        }

        if (str_starts_with($reference, '?')) {
            return $origin.($baseParts['path'] ?? '/').$reference;
        }

        $path = $baseParts['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin.($directory === '' ? '/' : $directory).$reference;
    }

    private static function httpOnly(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }
}
