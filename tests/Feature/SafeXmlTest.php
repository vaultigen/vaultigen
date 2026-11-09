<?php

use App\Services\Imports\SafeXml;

it('loads a small valid document', function () {
    expect(SafeXml::load('<Root><Child/></Root>')->documentElement?->nodeName)->toBe('Root');
});

it('rejects an oversized document', function () {
    expect(fn () => SafeXml::load('<Root/>', maxBytes: 3))
        ->toThrow(RuntimeException::class);
});

it('rejects a document with a doctype', function () {
    expect(fn () => SafeXml::load('<!DOCTYPE Root><Root/>'))
        ->toThrow(RuntimeException::class);
});

it('rejects a document with an entity declaration', function () {
    expect(fn () => SafeXml::load('<Root><!ENTITY x "y"></Root>'))
        ->toThrow(RuntimeException::class);
});

it('rejects invalid xml', function () {
    expect(fn () => SafeXml::load('<Root>'))
        ->toThrow(RuntimeException::class);
});

it('rejects a document nested too deeply', function () {
    expect(fn () => SafeXml::load('<Root><a><b/></a></Root>', maxDepth: 2))
        ->toThrow(RuntimeException::class);
});
