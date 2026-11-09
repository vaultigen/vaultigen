<?php

use App\Services\SiteIcons\HostResolver;
use App\Services\SiteIcons\SafeHttp;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\FakeHostResolver;

test('unsafe request URLs are refused before any connection', function (string $url) {
    Http::preventStrayRequests();
    Http::fake();

    expect(app(SafeHttp::class)->get($url, 16))->toBeNull();

    Http::assertNothingSent();
})->with([
    'malformed URL' => ['https://'],
    'credentials' => ['https://user:secret@example.com/'],
    'unsupported scheme' => ['file:///etc/passwd'],
]);

test('redirects are revalidated and pinned to the checked public IPv6 address', function () {
    $resolver = app(HostResolver::class);
    assert($resolver instanceof FakeHostResolver);
    $resolver->records = ['example.com' => ['93.184.216.34'], 'cdn.example.com' => ['2606:4700:4700::1111']];
    Http::preventStrayRequests();
    Http::fake([
        'https://example.com/' => Http::response('', 302, ['Location' => '//cdn.example.com/icon.png']),
        'https://cdn.example.com/icon.png' => function (Request $request, array $options) {
            expect($options['curl'][CURLOPT_RESOLVE])->toBe(['cdn.example.com:443:[2606:4700:4700::1111]'])
                ->and($options['allow_redirects'])->toBeFalse()
                ->and($options['proxy'])->toBe(['no' => ['*']]);
            ($options['progress'])(16, 16);

            return Http::response('0123456789abcdef', 200, ['Content-Type' => 'IMAGE/PNG; charset=binary']);
        },
    ]);

    expect(app(SafeHttp::class)->get('https://example.com/', 16))->toBe([
        'url' => 'https://cdn.example.com/icon.png',
        'content_type' => 'image/png',
        'body' => '0123456789abcdef',
    ]);
    Http::assertSentCount(2);
});

test('unusable responses return no body and stop within the redirect limit', function (string $scenario, int $requests) {
    $resolver = app(HostResolver::class);
    assert($resolver instanceof FakeHostResolver);
    $resolver->records = ['example.com' => ['93.184.216.34']];
    Http::preventStrayRequests();
    Http::fake(['https://example.com/' => match ($scenario) {
        'connection' => Http::failedConnection(),
        'transfer' => fn (Request $request) => throw new TransferException('Transfer interrupted', $request->toPsrRequest()),
        'missing location' => Http::response('', 302),
        'redirect loop' => Http::response('', 302, ['Location' => '/']),
        'declared oversize' => Http::response('small', 200, ['Content-Length' => '17']),
    }]);

    expect(app(SafeHttp::class)->get('https://example.com/', 16))->toBeNull();

    Http::assertSentCount($requests);
})->with([
    'connection failure' => ['connection', 1],
    'interrupted transfer' => ['transfer', 1],
    'missing redirect target' => ['missing location', 1],
    'redirect loop' => ['redirect loop', 4],
    'oversized Content-Length' => ['declared oversize', 1],
]);

test('the transfer callback aborts when either size counter exceeds the limit', function (int $expected, int $downloaded) {
    $resolver = app(HostResolver::class);
    assert($resolver instanceof FakeHostResolver);
    $resolver->records = ['example.com' => ['93.184.216.34']];
    Http::preventStrayRequests();
    Http::fake(['https://example.com/' => function (Request $request, array $options) use ($expected, $downloaded) {
        expect(fn () => ($options['progress'])($expected, $downloaded))
            ->toThrow(RuntimeException::class, 'The response exceeds the site icon size limit.');

        return Http::response('', 200);
    }]);

    app(SafeHttp::class)->get('https://example.com/', 16);

    Http::assertSentCount(1);
})->with([
    'declared total' => [17, 0],
    'download without declared total' => [0, 17],
]);
