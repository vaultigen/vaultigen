#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Run the test suite against each database configured in .env.testing.databases.
 *
 * Developers point the config file at databases that already run on their
 * machine (a native server, a local container, …). For every
 * driver the script runs `vendor/bin/pest` with the DB_* variables set on the
 * child process, so it works regardless of how the PHP binary is installed.
 *
 * Usage:
 *   php scripts/test-databases.php
 *   php scripts/test-databases.php --env-file=.env.testing.databases
 *   php scripts/test-databases.php --help
 *
 * Config format (see .env.testing.databases.example):
 *   DB_TEST_DRIVERS="mysql pgsql"
 *   DB_TEST_MYSQL_CONNECTION=mysql
 *   DB_TEST_MYSQL_HOST=127.0.0.1
 *   ...
 *
 * The host/port values must be reachable from wherever PHP runs. A native PHP
 * uses 127.0.0.1; a containerized PHP uses the container names.
 */

use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';

$root = dirname(__DIR__);
$logDir = $root.'/storage/logs';
$envFile = $root.'/.env.testing.databases';

/**
 * @param  list<string>  $arguments
 */
function print_usage(array $arguments): void
{
    $lines = [];
    $handle = fopen(__FILE__, 'r');
    if ($handle !== false) {
        for ($i = 0; $i < 32 && ($line = fgets($handle)) !== false; $i++) {
            $lines[] = $line;
        }
        fclose($handle);
    }

    $text = implode('', array_slice($lines, 2, 29));
    $text = preg_replace('/^# ?/m', '', $text) ?? $text;

    fwrite(STDOUT, rtrim($text).PHP_EOL);
    fwrite(STDOUT, PHP_EOL.'Executable: '.($arguments[0] ?? 'scripts/test-databases.php').PHP_EOL);
}

/**
 * @return array<string, string>
 */
function parse_env_file(string $path): array
{
    $values = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        $values[$key] = $value;
    }

    return $values;
}

function strip_ansi(string $value): string
{
    return preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $value) ?? $value;
}

function format_duration(float $seconds): string
{
    $total = (int) round($seconds);

    return sprintf('%dm%02ds', intdiv($total, 60), $total % 60);
}

$arguments = array_slice($argv, 1);

for ($i = 0; $i < count($arguments); $i++) {
    $argument = $arguments[$i];

    if ($argument === '-h' || $argument === '--help') {
        print_usage($argv);
        exit(0);
    }

    if ($argument === '--env-file') {
        $envFile = $arguments[++$i] ?? '';

        continue;
    }

    if (str_starts_with($argument, '--env-file=')) {
        $envFile = substr($argument, strlen('--env-file='));

        continue;
    }

    fwrite(STDERR, "Unknown argument: {$argument}".PHP_EOL);
    fwrite(STDERR, 'Run with --help for usage.'.PHP_EOL);
    exit(1);
}

if (! is_file($envFile)) {
    fwrite(STDERR, "Missing config file: {$envFile}".PHP_EOL);
    fwrite(STDERR, 'Copy .env.testing.databases.example to .env.testing.databases and fill in your databases.'.PHP_EOL);
    exit(1);
}

$config = parse_env_file($envFile);
$configured = getenv('DB_TEST_DRIVERS') ?: ($config['DB_TEST_DRIVERS'] ?? 'mysql pgsql');
$drivers = array_values(array_filter(preg_split('/\s+/', trim($configured)) ?: []));

if ($drivers === []) {
    fwrite(STDERR, 'No drivers configured. Set DB_TEST_DRIVERS in '.$envFile.'.'.PHP_EOL);
    exit(1);
}

if (! is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

$results = [];
$overall = 0;

foreach ($drivers as $driver) {
    $prefix = 'DB_TEST_'.strtoupper($driver);
    $connection = $config[$prefix.'_CONNECTION'] ?? $driver;
    $database = $config[$prefix.'_DATABASE'] ?? '';

    fwrite(STDOUT, PHP_EOL."==> {$driver} ({$connection})".PHP_EOL);

    if ($database === '') {
        $results[$driver] = ['status' => 'not configured', 'tests' => '-', 'seconds' => 0.0];
        fwrite(STDERR, "  Missing {$prefix}_DATABASE in {$envFile}".PHP_EOL);
        $overall = 1;

        continue;
    }

    $environment = [
        'DB_CONNECTION' => $connection,
        'DB_HOST' => $config[$prefix.'_HOST'] ?? '127.0.0.1',
        'DB_PORT' => $config[$prefix.'_PORT'] ?? '',
        'DB_DATABASE' => $database,
        'DB_USERNAME' => $config[$prefix.'_USERNAME'] ?? '',
        'DB_PASSWORD' => $config[$prefix.'_PASSWORD'] ?? '',
        'DB_URL' => '',
        'XDEBUG_MODE' => 'off',
        'PAO_DISABLE' => '1',
    ];

    fwrite(STDOUT, "    Running the suite against {$environment['DB_HOST']}:{$environment['DB_PORT']}".PHP_EOL);

    $started = microtime(true);

    $clear = new Process([PHP_BINARY, 'artisan', 'config:clear'], $root, $environment);
    $clear->setTimeout(120)->run();

    $process = new Process([PHP_BINARY, 'vendor/bin/pest', '--compact'], $root, $environment);
    $process->setTimeout(null)->run();

    $output = strip_ansi($process->getOutput().$process->getErrorOutput());
    $log = $logDir."/dbtest-{$driver}.log";
    file_put_contents($log, $output);

    $seconds = microtime(true) - $started;
    preg_match('/Tests:.*/', $output, $matches);

    $results[$driver] = [
        'status' => $process->isSuccessful() ? 'passed' : 'failed',
        'tests' => isset($matches[0]) ? trim($matches[0]) : 'no summary',
        'seconds' => $seconds,
        'log' => $log,
    ];

    if (! $process->isSuccessful()) {
        $overall = 1;
    }
}

fwrite(STDOUT, PHP_EOL.'================ Database test summary ================'.PHP_EOL);
printf('%-8s %-14s %-10s %s%s', 'driver', 'status', 'duration', 'tests', PHP_EOL);
printf('%-8s %-14s %-10s %s%s', '------', '------', '--------', '-----', PHP_EOL);

foreach ($drivers as $driver) {
    $result = $results[$driver] ?? ['status' => 'skipped', 'tests' => '-', 'seconds' => 0.0];
    printf(
        '%-8s %-14s %-10s %s%s',
        $driver,
        $result['status'],
        format_duration($result['seconds']),
        $result['tests'],
        PHP_EOL,
    );
}

fwrite(STDOUT, 'Logs: '.$logDir.'/dbtest-<driver>.log'.PHP_EOL);
fwrite(STDOUT, $overall === 0 ? 'All databases passed.'.PHP_EOL : 'Some databases failed.'.PHP_EOL);

exit($overall);
