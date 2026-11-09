<?php

namespace Tests\Fixtures {
    final class FakeSiteIconRuntime
    {
        /** @var array<string, list<array{ip?: string, ipv6?: string}>|false> */
        public static array $dns = [];

        public static ?float $time = null;
    }
}

namespace App\Services\SiteIcons {
    use Tests\Fixtures\FakeSiteIconRuntime;

    /**
     * @return list<array{ip?: string, ipv6?: string}>|false
     */
    function dns_get_record(string $host, int $type): array|false
    {
        return FakeSiteIconRuntime::$dns[$host] ?? false;
    }

    function microtime(bool $asFloat = false): string|float
    {
        return $asFloat && FakeSiteIconRuntime::$time !== null
            ? FakeSiteIconRuntime::$time
            : \microtime($asFloat);
    }
}
