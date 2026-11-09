<?php

use App\Services\Imports\ImportRegistry;
use App\Services\Imports\Syspass\SyspassXmlDriver;

it('resolves a registered driver and lists its label', function () {
    $registry = new ImportRegistry;
    $registry->register(SyspassXmlDriver::class);

    expect($registry->has('syspass_xml'))->toBeTrue()
        ->and($registry->has('missing'))->toBeFalse()
        ->and($registry->resolve('syspass_xml'))->toBeInstanceOf(SyspassXmlDriver::class)
        ->and($registry->options())->toBe(['syspass_xml' => 'sysPass (encrypted XML export)']);
});

it('rejects an unknown import source', function () {
    $registry = new ImportRegistry;

    expect(fn () => $registry->resolve('nope'))->toThrow(InvalidArgumentException::class);
});
