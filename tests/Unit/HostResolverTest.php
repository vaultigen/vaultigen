<?php

use App\Services\SiteIcons\HostResolver;
use Tests\Fixtures\FakeSiteIconRuntime;

require_once __DIR__.'/../Fixtures/FakeSiteIconRuntime.php';

afterEach(function () {
    FakeSiteIconRuntime::$dns = [];
});

test('literal addresses are accepted only when globally routable', function (string $host, ?string $expected) {
    expect((new HostResolver)->publicAddress($host))->toBe($expected);
})->with([
    'public IPv4' => ['93.184.216.34', '93.184.216.34'],
    'bracketed IPv6' => ['[2606:4700:4700::1111]', '2606:4700:4700::1111'],
]);

test('DNS answers must all be public before the first address can be used', function (array|false $records, ?string $expected) {
    FakeSiteIconRuntime::$dns = ['example.com' => $records];

    expect((new HostResolver)->publicAddress('example.com'))->toBe($expected);
})->with([
    'A and AAAA records' => [[['ip' => '93.184.216.34'], ['ipv6' => '2606:4700:4700::1111'], []], '93.184.216.34'],
    'lookup failure' => [false, null],
]);

test('malformed addresses are not public', function () {
    expect((new HostResolver)->isPublic('not-an-ip'))->toBeFalse();
});
