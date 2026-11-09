<?php

namespace Tests\Fixtures;

use App\Services\SiteIcons\HostResolver;

/**
 * A DNS stand-in for the site icon fetcher: hosts resolve only to the
 * addresses a test assigns, so no test ever reaches real DNS or the network.
 * The public-address policy itself is the real one.
 */
final class FakeHostResolver extends HostResolver
{
    /** @var array<string, list<string>> */
    public array $records = [];

    /**
     * @return list<string>
     */
    protected function addresses(string $host): array
    {
        return $this->records[$host] ?? [];
    }
}
