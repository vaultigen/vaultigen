<?php

namespace App\Services\SiteIcons;

/**
 * Resolves a host to the single public address the icon fetcher may connect to.
 *
 * A host is refused as a whole when any of its addresses is private, loopback,
 * link-local, reserved, or otherwise not globally routable, so a name that
 * mixes public and internal records cannot be used to reach the internal one.
 */
class HostResolver
{
    public function publicAddress(string $host): ?string
    {
        $addresses = $this->addresses($host);
        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                return null;
            }
        }

        return $addresses[0];
    }

    public function isPublic(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }

        // An IPv4-mapped IPv6 address (::ffff:a.b.c.d) is checked as the IPv4 address it reaches.
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $address = (string) inet_ntop(substr($packed, 12));
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /**
     * @return list<string>
     */
    protected function addresses(string $host): array
    {
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
