<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EntryDetail;
use App\Filament\Pages\Settings;
use App\Filament\Pages\VaultEntries;
use App\Jobs\FetchPendingSiteIcons;
use App\Models\SiteIcon;
use App\Models\User;
use App\Services\EntryVault;
use App\Services\Favorites;
use App\Services\InstallationSettings;
use App\Services\SiteIcons\HostResolver;
use App\Services\SiteIcons\SiteIconFetcher;
use App\Services\SiteIcons\SiteIcons;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fixtures\FakeHostResolver;
use Tests\Fixtures\FakeSiteIconRuntime;

require_once __DIR__.'/../Fixtures/FakeSiteIconRuntime.php';

afterEach(function () {
    FakeSiteIconRuntime::$time = null;
});

test('site icon jobs wait for the outer transaction to commit', function (string $method, array $arguments) {
    site_icon_pending('example.com');
    Queue::fake([FetchPendingSiteIcons::class]);

    DB::transaction(function () use ($method, $arguments): void {
        DB::transaction(function () use ($method, $arguments): void {
            app(SiteIcons::class)->{$method}(...$arguments);
        });

        Queue::assertNotPushed(FetchPendingSiteIcons::class);
    });

    Queue::assertPushed(FetchPendingSiteIcons::class, 1);
})->with([['request', ['new.example']], ['refresh', ['new.example']], ['dispatchIfPending', []]]);

test('site icon jobs are discarded when the transaction rolls back', function (string $method, array $arguments) {
    site_icon_pending('example.com');
    Queue::fake([FetchPendingSiteIcons::class]);

    DB::beginTransaction();
    try {
        app(SiteIcons::class)->{$method}(...$arguments);
    } finally {
        DB::rollBack();
    }

    Queue::assertNotPushed(FetchPendingSiteIcons::class);
    $this->assertDatabaseMissing('site_icons', ['host' => 'new.example']);
})->with([['request', ['new.example']], ['refresh', ['new.example']], ['dispatchIfPending', []]]);

/**
 * The header of a PNG of the given size: enough for PHP's image parser.
 */
function site_icon_png(int $width, int $height): string
{
    $ihdr = pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);

    return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
}

/**
 * @param  array<string, list<string>>  $records
 */
function site_icon_dns(array $records): void
{
    $resolver = app(HostResolver::class);
    assert($resolver instanceof FakeHostResolver);
    $resolver->records = $records;
}

function site_icon_run_job(): void
{
    app()->call([new FetchPendingSiteIcons, 'handle']);
}

function site_icon_pending(string $host): void
{
    DB::table('site_icons')->insert(['host' => $host, 'state' => SiteIcon::STATE_PENDING, 'created_at' => now(), 'updated_at' => now()]);
}

test('saving a login queues its host once and dispatches the fetch job', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    Queue::fake([FetchPendingSiteIcons::class]);

    vaultigen_make_entry($alice, $vault, ['url' => 'https://Example.com/login']);
    vaultigen_make_entry($alice, $vault, ['title' => 'Second', 'url' => 'https://example.com/other']);
    vaultigen_make_entry($alice, $vault, ['title' => 'Note', 'type' => 'note', 'body' => 'no url']);

    expect(SiteIcon::query()->pluck('state', 'host')->all())->toBe(['example.com' => SiteIcon::STATE_PENDING]);
    Queue::assertPushed(FetchPendingSiteIcons::class, 1);
});

test('updating a login to a new host queues that host', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    Queue::fake([FetchPendingSiteIcons::class]);

    app(EntryVault::class)->update($alice, $entry->id, vaultigen_entry_update($alice, ['url' => 'https://new.example.org/']), $entry->lock_version);

    expect(SiteIcon::query()->find('new.example.org')?->state)->toBe(SiteIcon::STATE_PENDING);
    Queue::assertPushed(FetchPendingSiteIcons::class);
});

test('nothing is queued while site icons are disabled', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    site_icon_pending('example.com');
    DB::table('installation_settings')->update(['site_icons_enabled' => false]);
    Queue::fake([FetchPendingSiteIcons::class]);
    Http::preventStrayRequests();
    Http::fake();

    vaultigen_make_entry($alice, $vault);
    app(SiteIcons::class)->refresh('new.example');
    site_icon_run_job();
    $this->artisan('vaultigen:refresh-site-icons')
        ->expectsOutputToContain('Site icons are turned off in Settings; nothing was queued.')
        ->assertSuccessful();

    expect(SiteIcon::query()->pluck('state', 'host')->all())->toBe(['example.com' => SiteIcon::STATE_PENDING]);
    Queue::assertNotPushed(FetchPendingSiteIcons::class);
    Http::assertNothingSent();
});

test('the fetcher stores the advertised icon closest to the display size', function () {
    site_icon_dns(['example.com' => ['93.184.216.34'], 'cdn.example.com' => ['93.184.216.35']]);
    site_icon_pending('example.com');
    Http::preventStrayRequests();
    Http::fake([
        'https://example.com/' => Http::response(<<<'HTML'
            <html><head>
            <link rel="icon" href="/favicon-16.png" sizes="16x16">
            <link rel="icon" href="/logo.svg" type="image/svg+xml">
            <link rel="apple-touch-icon" href="https://cdn.example.com/touch.png" sizes="180x180">
            <link rel="manifest" href="/site.webmanifest">
            </head></html>
            HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']),
        'https://example.com/site.webmanifest' => Http::response(['icons' => [
            ['src' => 'icons/icon-72.png', 'sizes' => '72x72', 'type' => 'image/png'],
            ['src' => 'icons/mask.png', 'sizes' => '64x64', 'purpose' => 'maskable'],
        ]]),
        'https://example.com/icons/icon-72.png' => Http::response(site_icon_png(72, 72), 200, ['Content-Type' => 'image/png']),
    ]);

    site_icon_run_job();

    $icon = SiteIcon::query()->findOrFail('example.com');
    expect($icon->state)->toBe(SiteIcon::STATE_FETCHED)
        ->and($icon->mime)->toBe('image/png')
        ->and(base64_decode((string) $icon->data))->toBe(site_icon_png(72, 72))
        ->and($icon->failures)->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '.svg') || str_contains($request->url(), 'mask.png'));
});

test('a body that is not a supported raster image falls through to the favicon', function () {
    site_icon_dns(['example.com' => ['93.184.216.34']]);
    site_icon_pending('example.com');
    Http::preventStrayRequests();
    Http::fake([
        'https://example.com/' => Http::response('<link rel="icon" href="/icon.png">', 200, ['Content-Type' => 'text/html']),
        'https://example.com/icon.png' => Http::response('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 200, ['Content-Type' => 'image/png']),
        'https://example.com/favicon.ico' => Http::response(site_icon_png(32, 32), 200, ['Content-Type' => 'image/x-icon']),
    ]);

    site_icon_run_job();

    expect(base64_decode((string) SiteIcon::query()->findOrFail('example.com')->data))->toBe(site_icon_png(32, 32));
});

test('unusable page metadata falls back to the favicon', function (string $html, ?string $manifest) {
    site_icon_dns(['example.com' => ['93.184.216.34']]);
    Http::preventStrayRequests();
    Http::fake([
        'https://example.com/' => Http::response($html, 200, ['Content-Type' => 'text/html']),
        'https://example.com/site.webmanifest' => $manifest === null ? Http::response('', 404) : Http::response($manifest),
        'https://example.com/favicon.ico' => Http::response(site_icon_png(32, 32)),
    ]);

    expect(app(SiteIconFetcher::class)->fetch('example.com'))->toBe(['mime' => 'image/png', 'bytes' => site_icon_png(32, 32)]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.com/favicon.ico');
})->with([
    'empty HTML' => ['', null],
    'invalid icon href' => ['<link rel="icon" href="data:image/png;base64,invalid">', null],
    'unavailable manifest' => ['<link rel="manifest" href="/site.webmanifest">', null],
    'invalid manifest JSON' => ['<link rel="manifest" href="/site.webmanifest">', '{'],
    'malformed manifest icons' => ['<link rel="manifest" href="/site.webmanifest">', '{"icons":[null,{}, {"src":42}]}'],
]);

test('images exceeding the maximum dimensions fall back to the favicon', function () {
    site_icon_dns(['example.com' => ['93.184.216.34']]);
    Http::preventStrayRequests();
    Http::fake([
        'https://example.com/' => Http::response('<link rel="icon" href="/huge.png" sizes="64x64">', 200, ['Content-Type' => 'text/html']),
        'https://example.com/huge.png' => Http::response(site_icon_png(1025, 32)),
        'https://example.com/favicon.ico' => Http::response(site_icon_png(32, 32)),
    ]);

    expect(app(SiteIconFetcher::class)->fetch('example.com'))->toBe(['mime' => 'image/png', 'bytes' => site_icon_png(32, 32)]);
    Http::assertSentCount(3);
});

test('the fetcher starts no more requests after its host budget expires', function () {
    FakeSiteIconRuntime::$time = 100.0;
    site_icon_dns(['example.com' => ['93.184.216.34']]);
    Http::preventStrayRequests();
    Http::fake(['https://example.com/' => function () {
        FakeSiteIconRuntime::$time += SiteIconFetcher::HOST_BUDGET_SECONDS;

        return Http::response('', 404);
    }]);

    expect(app(SiteIconFetcher::class)->fetch('example.com'))->toBeNull();
    Http::assertSentCount(1);
});

test('an oversized icon is rejected', function () {
    site_icon_dns(['example.com' => ['93.184.216.34']]);
    site_icon_pending('example.com');
    Http::preventStrayRequests();
    Http::fake([
        'https://example.com/' => Http::response('', 404),
        'http://example.com/' => Http::response('', 404),
        'https://example.com/favicon.ico' => Http::response(site_icon_png(32, 32).str_repeat("\0", 102400), 200),
    ]);

    site_icon_run_job();

    expect(SiteIcon::query()->findOrFail('example.com')->data)->toBeNull();
});

test('hosts that resolve to a non-public address are never contacted', function (array $addresses) {
    site_icon_dns(['intranet.example' => $addresses]);
    site_icon_pending('intranet.example');
    Http::preventStrayRequests();
    Http::fake();

    site_icon_run_job();

    Http::assertNothingSent();
    expect(SiteIcon::query()->findOrFail('intranet.example'))
        ->state->toBe(SiteIcon::STATE_FAILED)
        ->failures->toBe(1);
})->with([
    'loopback' => [['127.0.0.1']],
    'private' => [['10.0.0.5']],
    'cloud metadata' => [['169.254.169.254']],
    'carrier-grade NAT' => [['100.64.0.1']],
    'IPv6 loopback' => [['::1']],
    'IPv6 unique local' => [['fd00::1']],
    'IPv4-mapped loopback' => [['::ffff:127.0.0.1']],
    'mixed public and private' => [['93.184.216.34', '192.168.1.1']],
    'unresolvable' => [[]],
]);

test('redirects to internal hosts or non-default ports are not followed', function (string $location) {
    site_icon_dns(['example.com' => ['93.184.216.34'], 'internal.example' => ['10.0.0.1']]);
    site_icon_pending('example.com');
    Http::preventStrayRequests();
    Http::fake([
        'https://example.com/' => Http::response('', 302, ['Location' => $location]),
        'http://example.com/' => Http::response('', 404),
        'https://example.com/favicon.ico' => Http::response('', 404),
    ]);

    site_icon_run_job();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'internal.example') || str_contains($request->url(), ':8080'));
    Http::assertSentCount(3);
})->with([
    'private address' => ['http://internal.example/'],
    'non-default port' => ['https://example.com:8080/'],
]);

test('a failed refresh keeps the previous icon and backs off', function () {
    $this->freezeTime();
    site_icon_dns([]);
    DB::table('site_icons')->insert([
        'host' => 'example.com',
        'state' => SiteIcon::STATE_PENDING,
        'mime' => 'image/png',
        'data' => base64_encode('previous'),
        'checksum' => hash('sha256', 'previous'),
        'failures' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    site_icon_run_job();

    expect(SiteIcon::query()->findOrFail('example.com'))
        ->state->toBe(SiteIcon::STATE_FAILED)
        ->data->toBe(base64_encode('previous'))
        ->failures->toBe(2)
        ->next_check_at->toDateTimeString()->toBe(now()->addDays(7)->toDateTimeString());
});

test('the job stops after its time slice and queues its successor', function () {
    site_icon_dns(['first.example' => ['93.184.216.34']]);
    site_icon_pending('first.example');
    $this->travel(1)->seconds();
    site_icon_pending('second.example');
    Http::preventStrayRequests();
    Http::fake(['https://first.example/' => function () {
        $this->travel(FetchPendingSiteIcons::SLICE_SECONDS + 1)->seconds();

        return Http::response('', 404);
    }, '*' => Http::response('', 404)]);
    Queue::fake([FetchPendingSiteIcons::class]);

    site_icon_run_job();

    expect(SiteIcon::query()->orderBy('host')->pluck('state', 'host')->all())->toBe([
        'first.example' => SiteIcon::STATE_FAILED,
        'second.example' => SiteIcon::STATE_PENDING,
    ]);
    Queue::assertPushed(FetchPendingSiteIcons::class, 1);
});

test('a fetch exception is reported and backed off without blocking the next host', function () {
    $this->freezeTime();
    site_icon_pending('broken.example');
    site_icon_pending('next.example');
    $exception = new RuntimeException('Image processing failed');
    $this->mock(SiteIconFetcher::class, function ($mock) use ($exception) {
        $mock->shouldReceive('fetch')->with('broken.example')->once()->andThrow($exception);
        $mock->shouldReceive('fetch')->with('next.example')->once()->andReturn(['mime' => 'image/png', 'bytes' => site_icon_png(32, 32)]);
    });
    Exceptions::fake();

    site_icon_run_job();

    expect(SiteIcon::query()->findOrFail('broken.example'))
        ->state->toBe(SiteIcon::STATE_FAILED)
        ->failures->toBe(1)
        ->claimed_at->toBeNull()
        ->next_check_at->toDateTimeString()->toBe(now()->addDay()->toDateTimeString());
    expect(SiteIcon::query()->findOrFail('next.example'))
        ->state->toBe(SiteIcon::STATE_FETCHED)
        ->data->toBe(base64_encode(site_icon_png(32, 32)));
    Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);
});

test('a failed fetch for a removed host is safely discarded', function () {
    site_icon_pending('example.com');
    $icons = app(SiteIcons::class);
    expect($icons->claimNext())->toBe('example.com');
    DB::table('site_icons')->where('host', 'example.com')->delete();

    $icons->record('example.com', null);

    $this->assertDatabaseMissing('site_icons', ['host' => 'example.com']);
});

test('the scheduled refresh recovers, re-queues, backfills, and deletes unused icons', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    vaultigen_make_entry($alice, $vault, ['url' => 'https://stale.example/']);
    vaultigen_make_entry($alice, $vault, ['url' => 'https://claimed.example/']);
    vaultigen_make_entry($alice, $vault, ['url' => 'https://fresh.example/']);
    vaultigen_make_entry($alice, $vault, ['url' => 'https://missing.example/']);
    DB::table('site_icons')->delete();
    DB::table('site_icons')->insert([
        ['host' => 'stale.example', 'state' => SiteIcon::STATE_FETCHED, 'claimed_at' => null, 'next_check_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()],
        ['host' => 'claimed.example', 'state' => SiteIcon::STATE_FETCHING, 'claimed_at' => now()->subMinutes(11), 'next_check_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ['host' => 'fresh.example', 'state' => SiteIcon::STATE_FETCHED, 'claimed_at' => null, 'next_check_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()],
        ['host' => 'orphan.example', 'state' => SiteIcon::STATE_FETCHED, 'claimed_at' => null, 'next_check_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()],
    ]);
    Queue::fake([FetchPendingSiteIcons::class]);

    $this->artisan('vaultigen:refresh-site-icons')->assertSuccessful();

    expect(SiteIcon::query()->orderBy('host')->pluck('state', 'host')->all())->toBe([
        'claimed.example' => SiteIcon::STATE_PENDING,
        'fresh.example' => SiteIcon::STATE_FETCHED,
        'missing.example' => SiteIcon::STATE_PENDING,
        'stale.example' => SiteIcon::STATE_PENDING,
    ]);
    Queue::assertPushed(FetchPendingSiteIcons::class, 1);
});

test('the refresh options retry failed hosts or every host before they are due', function (array $options, array $expected) {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    vaultigen_make_entry($alice, $vault, ['url' => 'https://ok.example/']);
    vaultigen_make_entry($alice, $vault, ['url' => 'https://broken.example/']);
    DB::table('site_icons')->delete();
    DB::table('site_icons')->insert([
        ['host' => 'ok.example', 'state' => SiteIcon::STATE_FETCHED, 'next_check_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()],
        ['host' => 'broken.example', 'state' => SiteIcon::STATE_FAILED, 'next_check_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()],
    ]);
    Queue::fake([FetchPendingSiteIcons::class]);

    $this->artisan('vaultigen:refresh-site-icons', $options)->assertSuccessful();

    expect(SiteIcon::query()->orderBy('host')->pluck('state', 'host')->all())->toBe($expected);
})->with([
    'not due' => [[], ['broken.example' => SiteIcon::STATE_FAILED, 'ok.example' => SiteIcon::STATE_FETCHED]],
    'failed' => [['--failed' => true], ['broken.example' => SiteIcon::STATE_PENDING, 'ok.example' => SiteIcon::STATE_FETCHED]],
    'all' => [['--all' => true], ['broken.example' => SiteIcon::STATE_PENDING, 'ok.example' => SiteIcon::STATE_PENDING]],
]);

test('the icon endpoint serves the icon only to users who can see the entry', function () {
    ['alice' => $alice, 'bob' => $bob, 'carol' => $carol, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    $bytes = site_icon_png(32, 32);
    app(SiteIcons::class)->record('example.com', ['mime' => 'image/png', 'bytes' => $bytes]);

    $this->actingAs($bob)->get(route('entries.icon', ['entry' => $entry->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
        ->assertHeader('Cache-Control', 'max-age=86400, private')
        ->assertContent($bytes);

    $this->actingAs($carol)->get(route('entries.icon', ['entry' => $entry->id]))->assertNotFound();

    $etag = '"'.hash('sha256', $bytes).'"';
    $this->actingAs($bob)->get(route('entries.icon', ['entry' => $entry->id]), ['If-None-Match' => $etag])
        ->assertNoContent(304)
        ->assertHeader('ETag', $etag)
        ->assertHeader('Cache-Control', 'max-age=86400, private');

    $this->actingAs($carol)->get(route('entries.icon', ['entry' => $entry->id]), ['If-None-Match' => $etag])->assertNotFound();
});

test('the icon endpoint returns not found for an entry without a fetched icon', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);

    $this->actingAs($alice)->get(route('entries.icon', ['entry' => $entry->id]))->assertNotFound();
});

test('the entry list shows the fetched icon or a letter avatar', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $withIcon = vaultigen_make_entry($alice, $vault, ['title' => 'With icon', 'url' => 'https://example.com/']);
    vaultigen_make_entry($alice, $vault, ['title' => 'Without icon', 'url' => 'https://www.<b>.example/']);
    $bytes = site_icon_png(32, 32);
    app(SiteIcons::class)->record('example.com', ['mime' => 'image/png', 'bytes' => $bytes]);
    $this->actingAs($alice);

    $html = Livewire::test(VaultEntries::class, ['vault' => $vault->id])->html();

    expect($html)->toContain(e(route('entries.icon', ['entry' => $withIcon->id, 'v' => substr(hash('sha256', $bytes), 0, 12)])))
        ->toContain('data:image/svg+xml;base64,');
});

test('the dashboard favorites show the fetched icon or a letter avatar', function () {
    ['alice' => $alice, 'vault' => $vault] = vaultigen_entry_fixture();
    $withIcon = vaultigen_make_entry($alice, $vault, ['title' => 'With icon', 'url' => 'https://example.com/']);
    $withoutIcon = vaultigen_make_entry($alice, $vault, ['title' => 'Without icon', 'url' => 'https://other.example/']);
    $bytes = site_icon_png(32, 32);
    app(SiteIcons::class)->record('example.com', ['mime' => 'image/png', 'bytes' => $bytes]);
    app(Favorites::class)->toggleEntry($alice, $withIcon->id);
    app(Favorites::class)->toggleEntry($alice, $withoutIcon->id);

    $this->actingAs($alice)->get(Dashboard::getUrl())->assertOk()
        ->assertSee(route('entries.icon', ['entry' => $withIcon->id, 'v' => substr(hash('sha256', $bytes), 0, 12)]))
        ->assertSee('data:image/svg+xml;base64,', false);
});

test('managers can refresh an icon and viewers cannot', function () {
    ['alice' => $alice, 'bob' => $bob, 'vault' => $vault] = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($alice, $vault);
    DB::table('site_icons')->update(['state' => SiteIcon::STATE_FETCHED, 'next_check_at' => now()->addDays(30)]);
    Queue::fake([FetchPendingSiteIcons::class]);

    $this->actingAs($bob);
    Livewire::test(EntryDetail::class, ['entryId' => $entry->id])->assertActionDoesNotExist('refreshIcon');

    $this->actingAs($alice);
    Livewire::test(EntryDetail::class, ['entryId' => $entry->id])->callAction('refreshIcon')->assertNotified('Icon refresh queued');

    expect(SiteIcon::query()->findOrFail('example.com')->state)->toBe(SiteIcon::STATE_PENDING);
    Queue::assertPushed(FetchPendingSiteIcons::class);
});

test('admins can turn site icons off', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);

    Livewire::test(Settings::class)
        ->assertSet('data.site_icons_enabled', true)
        ->fillForm(['site_icons_enabled' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(InstallationSettings::class)->values()['site_icons_enabled'])->toBeFalse()
        ->and(app(SiteIcons::class)->enabled())->toBeFalse();
});
