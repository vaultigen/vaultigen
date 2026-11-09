<?php

namespace App\Services\SiteIcons;

use DOMDocument;
use JsonException;

/**
 * Finds and downloads the best raster icon a site advertises.
 *
 * The home page's `<link rel="icon">` / `apple-touch-icon` tags and its web
 * app manifest are ranked by how close they are to the display size, with
 * `/favicon.ico` as the last resort. SVG is never accepted because it can carry
 * script; every other image is checked by PHP's image header parser and only
 * PNG, ICO, GIF, JPEG, and WebP are kept.
 */
class SiteIconFetcher
{
    public const TARGET_SIZE = 64;

    public const MAX_PAGE_BYTES = 524288;

    public const MAX_MANIFEST_BYTES = 65536;

    public const MAX_ICON_BYTES = 102400;

    public const MAX_DIMENSION = 1024;

    public const MAX_CANDIDATES = 4;

    /** No new request starts once a host has used this many seconds. */
    public const HOST_BUDGET_SECONDS = 20;

    /** @var array<int, string> */
    private const ALLOWED_TYPES = [
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_ICO => 'image/x-icon',
        IMAGETYPE_GIF => 'image/gif',
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    private float $deadline = 0.0;

    public function __construct(private readonly SafeHttp $http) {}

    /**
     * @return array{mime: string, bytes: string}|null
     */
    public function fetch(string $host): ?array
    {
        $this->deadline = microtime(true) + self::HOST_BUDGET_SECONDS;

        $page = $this->get('https://'.$host.'/', self::MAX_PAGE_BYTES)
            ?? $this->get('http://'.$host.'/', self::MAX_PAGE_BYTES);
        $base = $page['url'] ?? 'https://'.$host.'/';

        $candidates = $page !== null && str_contains($page['content_type'], 'html')
            ? $this->candidatesFromPage($page['body'], $base)
            : [];

        $fallback = UrlResolver::resolve('/favicon.ico', $base);
        if ($fallback !== null) {
            $candidates[] = ['url' => $fallback, 'size' => null];
        }

        foreach ($this->rank($candidates) as $url) {
            $response = $this->get($url, self::MAX_ICON_BYTES);
            $icon = $response === null ? null : $this->validImage($response['body']);
            if ($icon !== null) {
                return $icon;
            }
        }

        return null;
    }

    /**
     * @return array{url: string, content_type: string, body: string}|null
     */
    private function get(string $url, int $maxBytes): ?array
    {
        return microtime(true) < $this->deadline ? $this->http->get($url, $maxBytes) : null;
    }

    /**
     * @return list<array{url: string, size: int|null}>
     */
    private function candidatesFromPage(string $html, string $base): array
    {
        $document = new DOMDocument;
        if ($html === '' || ! @$document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return [];
        }

        $candidates = [];
        foreach ($document->getElementsByTagName('link') as $link) {
            $rels = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel')))) ?: [];
            $url = UrlResolver::resolve($link->getAttribute('href'), $base);
            if ($url === null) {
                continue;
            }

            if (in_array('manifest', $rels, true)) {
                array_push($candidates, ...$this->candidatesFromManifest($url));

                continue;
            }

            $isIcon = in_array('icon', $rels, true)
                || in_array('apple-touch-icon', $rels, true)
                || in_array('apple-touch-icon-precomposed', $rels, true);
            if ($isIcon && ! $this->isSvg($url, $link->getAttribute('type'))) {
                $candidates[] = ['url' => $url, 'size' => $this->largestSize($link->getAttribute('sizes'))];
            }
        }

        return $candidates;
    }

    /**
     * @return list<array{url: string, size: int|null}>
     */
    private function candidatesFromManifest(string $manifestUrl): array
    {
        $response = $this->get($manifestUrl, self::MAX_MANIFEST_BYTES);
        if ($response === null) {
            return [];
        }

        try {
            $manifest = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $icons = is_array($manifest) && is_array($manifest['icons'] ?? null) ? $manifest['icons'] : [];
        $candidates = [];
        foreach ($icons as $icon) {
            if (! is_array($icon) || ! is_string($icon['src'] ?? null)) {
                continue;
            }

            $url = UrlResolver::resolve($icon['src'], $response['url']);
            $type = is_string($icon['type'] ?? null) ? $icon['type'] : '';
            $purpose = is_string($icon['purpose'] ?? null) ? $icon['purpose'] : 'any';
            if ($url === null || $this->isSvg($url, $type) || ! str_contains($purpose, 'any')) {
                continue;
            }

            $candidates[] = ['url' => $url, 'size' => $this->largestSize(is_string($icon['sizes'] ?? null) ? $icon['sizes'] : '')];
        }

        return $candidates;
    }

    /**
     * Order candidates by distance from the display size. Smaller icons are
     * penalised more than larger ones because they look blurry when scaled up;
     * an icon without a declared size sits between the two.
     *
     * @param  list<array{url: string, size: int|null}>  $candidates
     * @return list<string>
     */
    private function rank(array $candidates): array
    {
        $penalties = [];
        foreach ($candidates as $candidate) {
            $size = $candidate['size'];
            $penalty = match (true) {
                $size === null => 500,
                $size >= self::TARGET_SIZE => $size - self::TARGET_SIZE,
                default => (self::TARGET_SIZE - $size) * 8,
            };
            $penalties[$candidate['url']] = min($penalty, $penalties[$candidate['url']] ?? PHP_INT_MAX);
        }

        asort($penalties);

        return array_slice(array_map('strval', array_keys($penalties)), 0, self::MAX_CANDIDATES);
    }

    private function largestSize(string $sizes): ?int
    {
        preg_match_all('/(\d+)x(\d+)/i', $sizes, $matches);
        $widths = array_map('intval', $matches[1]);

        return $widths === [] ? null : max($widths);
    }

    private function isSvg(string $url, string $type): bool
    {
        return str_contains(strtolower($type), 'svg')
            || str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.svg');
    }

    /**
     * @return array{mime: string, bytes: string}|null
     */
    private function validImage(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);
        if (! is_array($info)) {
            return null;
        }

        [$width, $height, $type] = $info;
        $mime = self::ALLOWED_TYPES[$type] ?? null;
        if ($mime === null || $width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            return null;
        }

        return ['mime' => $mime, 'bytes' => $bytes];
    }
}
