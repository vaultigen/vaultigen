<?php

namespace App\Services\SiteIcons;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Outbound GET requests for the site icon fetcher, hardened against SSRF.
 *
 * Every hop, including each redirect target, must be http(s) on its default
 * port, carry no credentials, and resolve only to public addresses. The
 * connection is pinned to the checked address so a second DNS answer cannot
 * swap in an internal one. Redirects are followed manually so each target is
 * checked, and bodies are read up to a hard byte limit.
 */
class SafeHttp
{
    public const MAX_REDIRECTS = 3;

    public const CONNECT_TIMEOUT = 3;

    public const TIMEOUT = 6;

    public const USER_AGENT = 'Mozilla/5.0 (compatible; VaultigenIconFetcher/1.0)';

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @return array{url: string, content_type: string, body: string}|null
     */
    public function get(string $url, int $maxBytes): ?array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->target($url);
            if ($target === null) {
                return null;
            }

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'connect_timeout' => self::CONNECT_TIMEOUT,
                    'timeout' => self::TIMEOUT,
                    // Abort the transfer as soon as the body outgrows the limit.
                    // (Guzzle's `stream` option would switch to the PHP stream
                    // handler, which refuses the cURL pinning options below.)
                    'progress' => function (int $expected, int $downloaded) use ($maxBytes): void {
                        if ($expected > $maxBytes || $downloaded > $maxBytes) {
                            throw new RuntimeException('The response exceeds the site icon size limit.');
                        }
                    },
                    'protocols' => ['http', 'https'],
                    'curl' => [CURLOPT_RESOLVE => [$target['resolve']]],
                    // A proxy would resolve the host itself and void the pin above.
                    'proxy' => ['no' => ['*']],
                ])->withHeaders(['User-Agent' => self::USER_AGENT])->get($url);
            } catch (ConnectionException|TransferException) {
                return null;
            }

            if ($response->redirect()) {
                $next = UrlResolver::resolve($response->header('Location'), $url);
                if ($next === null) {
                    return null;
                }
                $url = $next;

                continue;
            }

            if (! $response->successful()) {
                return null;
            }

            $declaredLength = $response->header('Content-Length');
            if ($declaredLength !== '' && (int) $declaredLength > $maxBytes) {
                return null;
            }

            $body = $this->readLimited($response->toPsrResponse()->getBody(), $maxBytes);
            if ($body === null) {
                return null;
            }

            return [
                'url' => $url,
                'content_type' => strtolower(trim(explode(';', $response->header('Content-Type'))[0])),
                'body' => $body,
            ];
        }

        return null;
    }

    /**
     * @return array{resolve: string}|null
     */
    private function target(string $url): ?array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $defaultPort = match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => null,
        };
        if ($defaultPort === null || $host === '' || ($parts['port'] ?? $defaultPort) !== $defaultPort) {
            return null;
        }

        $address = $this->resolver->publicAddress($host);
        if ($address === null) {
            return null;
        }

        $pinned = str_contains($address, ':') ? '['.$address.']' : $address;

        return ['resolve' => trim($host, '[]').':'.$defaultPort.':'.$pinned];
    }

    private function readLimited(StreamInterface $stream, int $maxBytes): ?string
    {
        $body = '';
        while (! $stream->eof()) {
            $body .= $stream->read(8192);
            if (strlen($body) > $maxBytes) {
                $stream->close();

                return null;
            }
        }

        return $body;
    }
}
