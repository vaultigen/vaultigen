<?php

use GuzzleHttp\Client;
use Symfony\Component\Process\Process;
use Tests\Fixtures\ComposeProject;
use Tests\Fixtures\ContainerSandbox;

/*
 * Exercises the customer Compose files with an already-built local image
 * (sh scripts/docker/build.sh). Opt in with VAULTIGEN_COMPOSE_TESTS=1; run PHP
 * on the host with a working `docker compose` (for rootless Podman set
 * DOCKER_HOST=unix://$XDG_RUNTIME_DIR/podman/podman.sock). VAULTIGEN_TEST_IMAGE
 * selects the image (default localhost/vaultigen:local).
 */

beforeEach(function (): void {
    if (getenv('VAULTIGEN_COMPOSE_TESTS') !== '1') {
        $this->markTestSkipped('Set VAULTIGEN_COMPOSE_TESTS=1 to exercise the Compose installation.');
    }
    $this->projects = [];
});

afterEach(function (): void {
    foreach ($this->projects ?? [] as $project) {
        $project->destroy();
    }
});

function compose_stack_http(int $port, string $scheme = 'http'): Client
{
    return new Client(['base_uri' => "{$scheme}://localhost:{$port}", 'http_errors' => false, 'allow_redirects' => false, 'verify' => false, 'timeout' => 10]);
}

/**
 * The SHA-256 fingerprint of the certificate served on a loopback HTTPS port.
 */
function compose_stack_certificate(int $port): string
{
    $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'peer_name' => 'localhost']]);
    $socket = stream_socket_client("ssl://127.0.0.1:{$port}", context: $context, timeout: 10);
    $fingerprint = openssl_x509_fingerprint(stream_context_get_params($socket)['options']['ssl']['peer_certificate'], 'sha256');
    fclose($socket);

    return (string) $fingerprint;
}

it('installs, backs up, and restores onto a clean folder from one backup set and the key files', function (): void {
    $this->projects[] = $original = new ComposeProject('original');
    expect($original->install()->getOutput())->toContain('Setup completed.');
    expect($original->compose(['run', '--rm', '-T', 'setup'])->getOutput())->toContain('administrator creation is skipped');
    $original->compose(['up', '-d', '--wait']);
    $seeded = $original->drill('seed');
    preg_match('#/backups/([0-9a-f-]{36})#', $original->compose(['exec', '-T', 'web', 'php', 'artisan', 'vaultigen:backup'])->getOutput(), $match);

    $this->projects[] = $replacement = new ComposeProject('replacement');
    $replacement->adopt($original);
    $original->copyTo($replacement, ['backups/'.$match[1], 'data/keys']);
    $replacement->compose(['up', '-d', '--wait', 'database']);
    $restore = $replacement->compose(['run', '--rm', '--no-deps', '-T', 'setup', 'restore', '/backups/'.$match[1], '--force']);
    $replacement->compose(['up', '-d', '--wait']);

    expect($restore->getOutput())->toContain('Restore completed.')
        ->and($replacement->drill('verify'))->toBe($seeded)
        ->and($seeded)->toMatchArray(['secret' => true, 'attachment' => true, 'audit' => true]);
});

it('applies a pending migration once behind a verified backup and fails closed when that backup fails', function (): void {
    $this->projects[] = $project = new ComposeProject('migrate');
    $project->install();
    $project->compose(['up', '-d', '--wait']);
    $simulatePending = "DROP TABLE vault_images; DELETE FROM migrations WHERE migration = '2026_10_25_073822_create_vault_images'";

    $project->compose(['stop', 'web', 'worker', 'scheduler']);
    $project->psql($simulatePending);
    file_put_contents($project->directory.'/.env', "VAULTIGEN_MIN_FREE_BYTES=999999999999999999\n", FILE_APPEND);
    expect($project->compose(['up', '-d', '--wait'], mustSucceed: false)->isSuccessful())->toBeFalse();
    expect($project->compose(['logs', 'migrate'])->getOutput())->toContain('No migration was applied.')
        ->and($project->psql("SELECT to_regclass('vault_images') IS NULL"))->toBe('t')
        ->and($project->compose(['ps', '--status', 'running', '--services'])->getOutput())->not->toContain('web');

    file_put_contents($project->directory.'/.env', (string) preg_replace('/^VAULTIGEN_MIN_FREE_BYTES=.*\n/m', '', (string) file_get_contents($project->directory.'/.env')));
    $first = new Process(['docker', 'compose', 'run', '--rm', '--no-deps', '-T', 'migrate'], $project->directory, ComposeProject::environment(), timeout: 900);
    $second = new Process(['docker', 'compose', 'run', '--rm', '--no-deps', '-T', 'migrate'], $project->directory, ComposeProject::environment(), timeout: 900);
    $first->start();
    $second->start();
    $first->wait();
    $second->wait();

    expect($first->isSuccessful() && $second->isSuccessful())->toBeTrue()
        ->and(substr_count($first->getOutput().$second->getOutput(), 'Taking a verified backup first.'))->toBe(1)
        ->and(substr_count($first->getOutput().$second->getOutput(), 'No pending migrations.'))->toBe(1)
        ->and($project->psql("SELECT count(*) FROM backup_runs WHERE trigger = 'migration' AND state = 'completed'"))->toBe('1');
    $project->compose(['up', '-d', '--wait']);
});

it('serves direct HTTPS with a portless redirect and a persistent certificate, and refuses missing key files', function (): void {
    $this->projects[] = $project = new ComposeProject('https');
    $project->install();
    $project->compose(['up', '-d', '--wait']);

    $redirect = compose_stack_http($project->httpPort)->get('/login');
    expect($redirect->getStatusCode())->toBe(308)
        ->and($redirect->getHeaderLine('Location'))->toBe('https://localhost/login')
        ->and(compose_stack_http($project->httpsPort, 'https')->get('/login')->getStatusCode())->toBe(200);
    $certificate = compose_stack_certificate($project->httpsPort);
    $project->compose(['up', '-d', '--wait', '--force-recreate', 'web']);
    expect(compose_stack_certificate($project->httpsPort))->toBe($certificate);

    $project->compose(['rm', '--stop', '--force', 'web', 'worker', 'scheduler', 'migrate']);
    $project->compose(['run', '--rm', '--no-deps', '-T', '--entrypoint', 'sh', 'setup', '-c', 'rm -f /keys/*']);
    expect($project->compose(['up', '-d', '--wait'], mustSucceed: false)->isSuccessful())->toBeFalse()
        ->and($project->compose(['logs', 'migrate'])->getOutput())->toContain('Installation keys are missing')
        ->and($project->compose(['run', '--rm', '-T', 'setup'], mustSucceed: false)->getOutput())->toContain('Setup never generates replacement keys');
});

it('serves only the loopback proxy port and trusts the configured proxy address', function (): void {
    $trusted = getenv('VAULTIGEN_COMPOSE_TRUSTED_PROXY') ?: null;
    $this->projects[] = $project = new ComposeProject('proxy', ['compose.proxy.yaml'], $trusted === null ? [] : ['VAULTIGEN_TRUSTED_PROXIES' => $trusted], probe: true);
    $project->install();
    $project->compose(['up', '-d', '--wait']);

    $client = json_decode((string) compose_stack_http($project->httpPort)->get('/__container_probe.php?action=client', [
        'headers' => ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'],
    ])->getBody(), true);
    expect($client)->toMatchArray(['ip' => '203.0.113.9', 'secure' => true]);
    expect($project->compose(['port', 'web', '8443'], mustSucceed: false)->isSuccessful())->toBeFalse();
});

it('passes single-quoted secrets with special characters literally to the database and the application', function (): void {
    $password = 'Db$1#2 "x\'y${HOME}`id`-'.bin2hex(random_bytes(8));
    $quote = fn (string $value): string => "'".str_replace("'", "\\'", $value)."'";
    $this->projects[] = $project = new ComposeProject('secrets', environment: ['DB_PASSWORD' => $quote($password), 'MAIL_PASSWORD' => $quote('Mail$2 #'.$password)]);

    expect($project->install()->getOutput())->toContain('Setup completed.');
    $configured = $project->compose(['run', '--rm', '--no-deps', '-T', 'web', 'php', '-r', 'chdir("/app"); require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo json_encode([config("database.connections.pgsql.password"), config("mail.mailers.smtp.password")]);'])->getOutput();

    expect(json_decode(trim($configured), true))->toBe([$password, 'Mail$2 #'.$password]);
});

it('runs a copy of the stopped installation folder on another directory with the same identity and data', function (): void {
    $this->projects[] = $original = new ComposeProject('folder');
    $original->install();
    $original->compose(['up', '-d', '--wait']);
    $seeded = $original->drill('seed');
    $original->compose(['stop']);

    $this->projects[] = $copy = new ComposeProject('folder-copy');
    $copy->adopt($original);
    $original->copyTo($copy, ['data', 'backups']);
    $copy->compose(['up', '-d', '--wait']);

    expect($copy->drill('verify'))->toBe($seeded);
});

it('upgrades every role to a new image behind a verified backup and rolls back to that backup with the previous image', function (): void {
    $this->projects[] = $project = new ComposeProject('upgrade');
    $previous = getenv('VAULTIGEN_TEST_IMAGE') ?: 'localhost/vaultigen:local';
    $next = 'localhost/'.$project->name.':next';
    $docker = fn (array $arguments): string => trim((new Process(['docker', ...$arguments], env: ComposeProject::environment(), timeout: 300))->mustRun()->getOutput());
    $docker(['create', '--name', $project->name.'-next', $previous]);
    $nextId = $docker(['commit', '--change', 'LABEL org.vaultigen.test=next', $project->name.'-next', $next]);
    $docker(['rm', $project->name.'-next']);
    $project->install();
    $project->compose(['up', '-d', '--wait']);
    $seeded = $project->drill('seed');
    $project->compose(['stop', 'web', 'worker', 'scheduler']);
    $project->psql("DROP TABLE vault_images; DELETE FROM migrations WHERE migration = '2026_10_25_073822_create_vault_images'");

    try {
        $project->configure(['VAULTIGEN_LOCAL_IMAGE' => $next]);
        $project->compose(['stop', 'web', 'worker', 'scheduler']);
        $project->compose(['up', '-d', '--wait']);

        expect(array_map(fn (string $service): string => $project->imageOf($service), ['web', 'worker', 'scheduler']))
            ->each->toEndWith(substr($nextId, -12))
            ->and($project->psql("SELECT to_regclass('vault_images') IS NOT NULL"))->toBe('t')
            ->and($project->drill('verify'))->toBe($seeded);
        $set = basename($project->psql("SELECT path FROM backup_runs WHERE trigger = 'migration' AND state = 'completed'"));

        $project->compose(['stop', 'web', 'worker', 'scheduler']);
        $project->configure(['VAULTIGEN_LOCAL_IMAGE' => $previous]);
        $restore = $project->compose(['run', '--rm', '--no-deps', '-T', 'setup', 'restore', '/backups/'.$set, '--force']);

        expect($restore->getOutput())->toContain('Restore completed.')
            ->and($project->psql("SELECT to_regclass('vault_images') IS NULL"))->toBe('t')
            ->and($project->drill('verify', oneOff: true))->toBe($seeded);
    } finally {
        $project->compose(['rm', '--stop', '--force'], mustSucceed: false);
        (new Process(['docker', 'rmi', $next], env: ComposeProject::environment()))->run();
    }
});

it('moves a manual PostgreSQL installation into Compose with its identity, keys, and data', function (): void {
    $sandbox = new ContainerSandbox('docker', getenv('VAULTIGEN_TEST_IMAGE') ?: 'localhost/vaultigen:local');
    $this->projects[] = $target = new ComposeProject('manual-move');

    try {
        $server = $sandbox->start('pgsql', ['-e', 'POSTGRES_DB=manual', '-e', 'POSTGRES_USER=manual', '-e', 'POSTGRES_PASSWORD=manual-test-only-password-0123', 'docker.io/library/postgres:18-trixie']);
        $storage = $sandbox->volume('manual-storage');
        $appKey = 'base64:'.base64_encode(random_bytes(32));
        $manual = [
            'APP_KEY='.$appKey, 'DB_CONNECTION=pgsql', 'DB_HOST='.$server, 'DB_PORT=5432',
            'DB_DATABASE=manual', 'DB_USERNAME=manual', 'DB_PASSWORD=manual-test-only-password-0123', 'MAIL_MAILER=log',
        ];
        $sandbox->waitUntil(fn (): bool => $sandbox->oneOff($storage, ['artisan', 'db:monitor', '--databases=pgsql'], $manual, false)->isSuccessful(), 60, 'PostgreSQL did not become ready.', $server);
        foreach (['vaultigen:initialize', 'vaultigen:backup-key:generate'] as $command) {
            $sandbox->oneOff($storage, ['artisan', $command, '--no-interaction'], $manual);
        }
        $sandbox->oneOff($storage, ['artisan', 'migrate', '--force', '--no-interaction'], $manual);
        $sandbox->oneOff($storage, ['artisan', 'vaultigen:bootstrap', 'admin@example.com'], [...$manual, 'SHELL_INTERACTIVE=1'], input: "Zq7!vR2#mW9xLp4TbN8s\n");
        $seeded = json_decode($sandbox->oneOff($storage, ['php', '--', 'seed'], $manual, input: (string) file_get_contents(base_path('tests/Fixtures/container-compose-data.php')))->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        preg_match('#backups/([0-9a-f-]{36})#', $sandbox->oneOff($storage, ['artisan', 'vaultigen:backup'], $manual)->getOutput(), $match);
        $files = $sandbox->oneOff($storage, ['sh', '-c', 'tar -C /app/storage/app/private -cf - "backups/$0" installation.json kek.json backup.json', $match[1]], $manual)->getOutput();

        mkdir($target->directory.'/data/keys', 0700, true);
        (new Process(['tar', '-xf', '-', '-C', $target->directory.'/backups', '--strip-components=1', 'backups/'.$match[1]]))->setInput($files)->mustRun();
        (new Process(['tar', '-xf', '-', '-C', $target->directory.'/data/keys', 'installation.json', 'kek.json', 'backup.json']))->setInput($files)->mustRun();
        $target->configure(['APP_KEY' => $appKey]);
        $target->compose(['up', '-d', '--wait', 'database']);
        $restore = $target->compose(['run', '--rm', '--no-deps', '-T', 'setup', 'restore', '/backups/'.$match[1], '--force']);
        $target->compose(['up', '-d', '--wait']);

        expect($restore->getOutput())->toContain('Restore completed.')
            ->and($target->drill('verify'))->toBe($seeded)
            ->and($seeded)->toMatchArray(['secret' => true, 'attachment' => true, 'audit' => true]);
    } finally {
        $sandbox->destroy();
    }
});
