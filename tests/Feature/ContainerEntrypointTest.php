<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 * @return array{exit: int|null, calls: list<array{binary: string, arguments: list<string>, version: string|false, stale_cache: bool}>, private_files: list<string>}
 */
function container_entrypoint_run(array $arguments, array $environment = []): array
{
    $directory = sys_get_temp_dir().'/vaultigen-entrypoint-'.bin2hex(random_bytes(12));
    File::ensureDirectoryExists($directory.'/scripts/docker', 0700);
    File::ensureDirectoryExists($directory.'/bootstrap/cache', 0700);
    File::ensureDirectoryExists($directory.'/bin', 0700);
    File::copy(base_path('scripts/docker/entrypoint.sh'), $directory.'/scripts/docker/entrypoint.sh');
    File::put($directory.'/bootstrap/cache/config.php', 'stale-secret');
    $stub = '#!'.PHP_BINARY."\n".<<<'PHP'
<?php
$arguments = array_slice($argv, 1);
file_put_contents(getenv('CALL_LOG'), json_encode([
    'binary' => basename($argv[0]),
    'arguments' => $arguments,
    'stale_cache' => is_file(getenv('APP_CONFIG_CACHE')),
], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
if (($arguments[1] ?? null) === 'config:cache') {
    exit((int) getenv('CACHE_EXIT'));
}
if (($arguments[1] ?? null) !== null && $arguments[1] === getenv('FAIL_COMMAND')) {
    exit(9);
}
exit((int) getenv('ROLE_EXIT'));
PHP;
    foreach (['php', 'frankenphp'] as $binary) {
        File::put($directory.'/bin/'.$binary, $stub);
        chmod($directory.'/bin/'.$binary, 0700);
    }
    File::put($directory.'/bin/id', "#!/bin/sh\necho \"\${STUB_UID:-10001}\"\n");
    chmod($directory.'/bin/id', 0700);

    try {
        $process = new Process(['sh', $directory.'/scripts/docker/entrypoint.sh', ...$arguments], env: array_merge([
            'PATH' => $directory.'/bin:'.getenv('PATH'),
            'CALL_LOG' => $directory.'/calls.jsonl',
        ], $environment));
        $process->run();

        return [
            'exit' => $process->getExitCode(),
            'calls' => array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), is_file($directory.'/calls.jsonl') ? file($directory.'/calls.jsonl', FILE_IGNORE_NEW_LINES) : []),
            'private_files' => array_map('basename', glob($directory.'/storage/app/private/*') ?: []),
        ];
    } finally {
        File::deleteDirectory($directory);
    }
}

it('refreshes stale configuration before starting the web server without generating installation secrets', function (): void {
    $result = container_entrypoint_run([]);

    expect($result['exit'])->toBe(0);
    expect($result['calls'])->toBe([
        ['binary' => 'php', 'arguments' => ['artisan', 'config:cache', '--no-interaction'], 'stale_cache' => false],
        ['binary' => 'frankenphp', 'arguments' => ['run', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile'], 'stale_cache' => false],
    ]);
    expect($result['private_files'])->toBe(['tmp']);
});

it('does not start a role when configuration caching fails', function (): void {
    $result = container_entrypoint_run(['worker'], ['CACHE_EXIT' => '23']);

    expect($result['exit'])->toBe(23);
    expect($result['calls'])->toHaveCount(1);
});

it('preserves administrative arguments and forwards the process exit status', function (): void {
    $result = container_entrypoint_run(['artisan', 'vaultigen:backup:verify', '/backups/a set $with #characters'], ['ROLE_EXIT' => '17']);

    expect($result['exit'])->toBe(17);
    expect($result['calls'][1]['arguments'])->toBe(['artisan', 'vaultigen:backup:verify', '/backups/a set $with #characters']);
});

it('runs background roles in the foreground with production defaults', function (string $role, array $expected): void {
    $result = container_entrypoint_run([$role]);

    expect($result['exit'])->toBe(0);
    expect($result['calls'][1]['arguments'])->toBe($expected);
})->with([
    'queue worker' => ['worker', ['artisan', 'queue:work', '--sleep=3', '--tries=1', '--timeout=3600']],
    'scheduler' => ['scheduler', ['artisan', 'schedule:work', '--no-interaction']],
]);

it('supports direct commands without expanding their arguments', function (): void {
    $result = container_entrypoint_run(['php', '-r', 'echo "$literal";']);

    expect($result['calls'][1]['arguments'])->toBe(['-r', 'echo "$literal";']);
});

it('runs setup without caching configuration so new keys are read', function (): void {
    $result = container_entrypoint_run(['setup', '--email=admin@example.com']);

    expect($result['exit'])->toBe(0);
    expect(array_column($result['calls'], 'arguments'))->toBe([['artisan', 'vaultigen:setup', '--email=admin@example.com']]);
});

it('runs the migration gate after refreshing configuration', function (): void {
    $result = container_entrypoint_run(['migrate'], ['ROLE_EXIT' => '1']);

    expect($result['exit'])->toBe(1);
    expect(array_column($result['calls'], 'arguments'))->toBe([
        ['artisan', 'config:cache', '--no-interaction'],
        ['artisan', 'vaultigen:migrate', '--no-interaction'],
    ]);
});

it('restores offline and brings the installation up only afterwards', function (): void {
    $result = container_entrypoint_run(['restore', '/backups/run-1', '--force']);

    expect($result['exit'])->toBe(0);
    expect(array_column($result['calls'], 'arguments'))->toBe([
        ['artisan', 'down', '--no-interaction'],
        ['artisan', 'vaultigen:restore', '/backups/run-1', '--services-stopped', '--force'],
        ['artisan', 'up', '--no-interaction'],
    ]);
});

it('keeps the installation down when the restore fails', function (): void {
    $result = container_entrypoint_run(['restore', '/backups/run-1'], ['FAIL_COMMAND' => 'vaultigen:restore']);

    expect($result['exit'])->toBe(9);
    expect(array_column($result['calls'], 'arguments'))->not->toContain(['artisan', 'up', '--no-interaction']);
});

it('refuses to start a long-running role as root', function (string $role): void {
    $result = container_entrypoint_run([$role], ['STUB_UID' => '0']);

    expect($result['exit'])->toBe(1);
    expect($result['calls'])->toBe([]);
})->with(['web', 'migrate', 'worker', 'scheduler', 'artisan']);
