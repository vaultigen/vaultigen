<?php

use App\Services\SiteIcons\UrlResolver;

test('icon references resolve safely against their page URL', function (?string $reference, string $base, ?string $expected) {
    expect(UrlResolver::resolve($reference, $base))->toBe($expected);
})->with([
    'missing href' => [null, 'https://example.com/', null],
    'invalid base' => ['/icon.png', 'https://', null],
    'scheme relative with fragment' => ['//cdn.example.com/icon.png#icon', 'https://example.com/', 'https://cdn.example.com/icon.png'],
    'query relative with port' => ['?icon=1', 'https://example.com:443/page', 'https://example.com:443/page?icon=1'],
]);
