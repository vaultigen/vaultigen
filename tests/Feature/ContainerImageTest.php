<?php

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;
use Tests\Fixtures\ContainerSandbox;

/*
 * Exercises an already-built local production image (sh scripts/docker/build.sh).
 * Opt in with VAULTIGEN_CONTAINER_TESTS=1; CONTAINER_ENGINE and VAULTIGEN_TEST_IMAGE
 * select the engine (default docker) and image (default vaultigen:local).
 */

beforeEach(function (): void {
    if (getenv('VAULTIGEN_CONTAINER_TESTS') !== '1') {
        $this->markTestSkipped('Set VAULTIGEN_CONTAINER_TESTS=1 to exercise the locally built production image.');
    }

    $this->sandbox = ContainerSandbox::fromEnvironment();
});

afterEach(function (): void {
    if (isset($this->sandbox)) {
        $this->sandbox->destroy();
    }
});

/**
 * Forward cookies manually: the production image sets secure cookies, and this
 * isolated loopback connection is plain HTTP.
 */
function container_image_cookies(ResponseInterface $response): string
{
    return implode('; ', array_map(fn (string $cookie): string => explode(';', $cookie, 2)[0], $response->getHeader('Set-Cookie')));
}

it('passes the production runtime checks inside the image', function (): void {
    $storage = $this->sandbox->sqliteStorage();

    expect($this->sandbox->oneOff($storage, ['php', '/checks/runtime.php'])->getOutput())->toContain('Production runtime checks passed.');
    expect($this->sandbox->oneOff($storage, ['php', '/checks/runtime.php', 'snapshot'])->getOutput())->toContain('Native snapshot round trip passed: sqlite');
    expect($this->sandbox->oneOff($storage, ['frankenphp', 'validate', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile'])->isSuccessful())->toBeTrue();
});

it('serves Filament, Livewire, sessions, uploads, redirects and streams, then stops gracefully', function (): void {
    $storage = $this->sandbox->sqliteStorage();
    $web = $this->sandbox->start('web', [
        '-p', '127.0.0.1::8080', '-v', $storage.':/app/storage',
        '-v', ContainerSandbox::probePath().':/app/public/__container_probe.php:ro',
        ...ContainerSandbox::environmentArguments(ContainerSandbox::ENVIRONMENT),
        $this->sandbox->image, 'web',
    ]);
    $binding = strtok($this->sandbox->output(['port', $web, '8080/tcp']), "\n");
    $http = new Client([
        'base_uri' => 'http://127.0.0.1:'.substr($binding, strrpos($binding, ':') + 1),
        'http_errors' => false,
        'allow_redirects' => false,
        'timeout' => 10,
    ]);
    $this->sandbox->waitUntil(function () use ($http): bool {
        try {
            return $http->get('/up')->getStatusCode() === 200;
        } catch (Throwable) {
            return false;
        }
    }, 60, 'Web runtime did not become healthy.', $web);

    $login = $http->get('/login');
    $page = (string) $login->getBody();
    expect($login->getStatusCode())->toBe(200);
    expect($page)->toContain('wire:snapshot');
    expect($http->get('/.env')->getStatusCode())->toBe(404);
    expect($http->get('/storage/installation.json')->getStatusCode())->toBe(404);

    preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page, $token);
    preg_match('/data-update-uri="([^"]+)"/', $page, $updateUri);
    preg_match_all('/wire:snapshot="([^"]+)"/', $page, $snapshots);
    $snapshot = collect($snapshots[1])
        ->map(fn (string $value): string => html_entity_decode($value))
        ->first(fn (string $value): bool => str_contains(strtolower(json_decode($value, true)['memo']['name']), 'login'));
    expect($snapshot)->not->toBeNull();
    $update = $http->post(parse_url(html_entity_decode($updateUri[1]), PHP_URL_PATH), [
        'headers' => ['Cookie' => container_image_cookies($login), 'X-Livewire' => 'true'],
        'json' => ['_token' => html_entity_decode($token[1]), 'components' => [[
            'snapshot' => $snapshot, 'updates' => (object) [],
            'calls' => [['path' => '', 'method' => 'authenticate', 'params' => []]],
        ]]],
    ]);
    expect($update->getStatusCode())->toBe(200);
    expect(json_decode((string) $update->getBody(), true))->toHaveKey('components');

    $first = $http->get('/__container_probe.php');
    $cookies = container_image_cookies($first);
    $second = $http->get('/__container_probe.php', ['headers' => ['Cookie' => $cookies]]);
    expect(json_decode((string) $second->getBody(), true)['count'])->toBe(2);

    $upload = $http->post('/__container_probe.php?action=upload', [
        'headers' => ['Cookie' => $cookies],
        'multipart' => [
            ['name' => '_token', 'contents' => json_decode((string) $first->getBody(), true)['csrf']],
            ['name' => 'upload', 'contents' => 'synthetic upload', 'filename' => 'probe.txt', 'headers' => ['Content-Type' => 'text/plain']],
        ],
    ]);
    expect(json_decode((string) $upload->getBody(), true))->toBe(['valid' => true, 'private' => true, 'contents' => 'synthetic upload']);
    expect($http->get('/__container_probe.php?action=redirect')->getStatusCode())->toBe(302);
    expect((string) $http->get('/__container_probe.php?action=stream')->getBody())->toBe('container-stream-complete');

    expect($this->sandbox->stop($web))->toBe('0');
});

it('runs the :role as UID 10001 and stops gracefully', function (string $role): void {
    $storage = $this->sandbox->sqliteStorage();
    $container = $this->sandbox->start($role, [
        '-v', $storage.':/app/storage',
        '-v', ContainerSandbox::probePath().':/checks/runtime.php:ro',
        ...ContainerSandbox::environmentArguments(ContainerSandbox::ENVIRONMENT),
        $this->sandbox->image, $role, '--verbose',
    ]);
    if ($role === 'worker') {
        $this->sandbox->oneOff($storage, ['artisan', 'vaultigen:heartbeat', '--no-interaction']);
    }

    $this->sandbox->waitUntil(fn (): bool => $role === 'worker'
        ? $this->sandbox->run(['exec', $container, 'php', '/checks/runtime.php', 'heartbeat'], false)->isSuccessful()
        : str_contains($this->sandbox->run(['logs', $container], false)->getOutput(), 'Running scheduled tasks'),
        30, $role.' did not start.', $container);

    expect($this->sandbox->output(['exec', $container, 'id', '-u']))->toBe('10001');
    expect($this->sandbox->stop($container))->toBe('0');
})->with(['worker', 'scheduler']);

it('round-trips native snapshots against :driver with the bundled clients', function (string $driver, string $image, array $serverEnvironment, string $port): void {
    $server = $this->sandbox->start($driver, [...ContainerSandbox::environmentArguments($serverEnvironment), $image]);
    $environment = [
        ContainerSandbox::ENVIRONMENT[0], 'DB_CONNECTION='.$driver, 'DB_HOST='.$server, 'DB_PORT='.$port,
        'DB_DATABASE=probe', 'DB_USERNAME=probe', 'DB_PASSWORD=probe-test-only',
    ];
    $storage = $this->sandbox->volume($driver.'-storage');
    $this->sandbox->waitUntil(fn (): bool => $this->sandbox->oneOff($storage, [
        'php', '-r', 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); Illuminate\Support\Facades\DB::connection()->getPdo();',
    ], $environment, false)->isSuccessful(), 60, $driver.' did not become ready.', $server);

    $this->sandbox->oneOff($storage, ['artisan', 'migrate', '--force', '--no-interaction'], $environment);

    expect($this->sandbox->oneOff($storage, ['php', '/checks/runtime.php', 'snapshot'], $environment)->getOutput())
        ->toContain('Native snapshot round trip passed: '.$driver);
})->with([
    'pgsql' => ['pgsql', 'docker.io/library/postgres:18-trixie', ['POSTGRES_DB=probe', 'POSTGRES_USER=probe', 'POSTGRES_PASSWORD=probe-test-only'], '5432'],
    'mysql' => ['mysql', 'docker.io/library/mysql:8.4', ['MYSQL_DATABASE=probe', 'MYSQL_USER=probe', 'MYSQL_PASSWORD=probe-test-only', 'MYSQL_ROOT_PASSWORD=root-test-only'], '3306'],
    'mariadb' => ['mariadb', 'docker.io/library/mariadb:11.4', ['MARIADB_DATABASE=probe', 'MARIADB_USER=probe', 'MARIADB_PASSWORD=probe-test-only', 'MARIADB_ROOT_PASSWORD=root-test-only'], '3306'],
]);

it('keeps host state and development files out of every image layer', function (): void {
    $directory = sys_get_temp_dir().'/vaultigen-image-layers-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);

    try {
        $this->sandbox->output(['save', '--output', $directory.'/image.tar', $this->sandbox->image]);
        (new Process(['tar', '-xf', $directory.'/image.tar', '-C', $directory], timeout: 600))->mustRun();
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $forbidden = [];
        foreach ($manifest[0]['Layers'] as $layer) {
            $listing = (new Process(['tar', '-tf', $directory.'/'.$layer], timeout: 600))->mustRun()->getOutput();
            foreach (explode("\n", trim($listing)) as $path) {
                $path = preg_replace('#^\./#', '', $path);
                // Directory entries end in "/"; only files and links can carry host state.
                if (! str_ends_with($path, '/') && preg_match('#^app/(?:\.env[^/]*|\.git/|node_modules/|tests/|docs/(?!legal/)|auth\.json$|bootstrap/cache/config\.php$|database/(?:factories|seeders)/.*\.php$|storage/)#', $path)) {
                    $forbidden[] = $path;
                }
            }
        }

        expect($forbidden)->toBe([]);
    } finally {
        (new Process(['rm', '-rf', $directory]))->run();
    }
});
