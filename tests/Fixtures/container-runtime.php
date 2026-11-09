<?php

use App\Services\Operations\DatabaseSnapshot;
use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

require '/app/vendor/autoload.php';
$application = require '/app/bootstrap/app.php';

if (PHP_SAPI !== 'cli') {
    $request = Request::capture();
    $application->instance('request', $request);
    $application->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
    Route::middleware('web')->any($request->getPathInfo(), function (Request $request): Response {
        return match ($request->query('action')) {
            'redirect' => redirect('/login'),
            'client' => response()->json([
                'ip' => $request->ip(), 'secure' => $request->secure(),
                'remote' => $request->server('REMOTE_ADDR'), 'host' => $request->getHost(),
            ]),
            'stream' => response()->streamDownload(function (): void {
                echo 'container-stream-';
                flush();
                echo 'complete';
            }, 'probe.txt'),
            'upload' => response()->json([
                'valid' => $request->file('upload')?->isValid(),
                'private' => str_starts_with($request->file('upload')?->getPathname() ?? '', '/app/storage/app/private/tmp/'),
                'contents' => $request->file('upload')?->get(),
            ]),
            default => response()->json([
                'count' => tap((int) session('probe_count', 0) + 1, fn (int $count): mixed => session(['probe_count' => $count])),
                'csrf' => csrf_token(),
            ]),
        };
    });
    $application->handleRequest($request);

    return;
}

$application->make(Kernel::class)->bootstrap();

function require_container_condition(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

if (($argv[1] ?? '') === 'heartbeat') {
    exit(DB::table('operational_heartbeats')->where('name', 'queue')->exists() ? 0 : 1);
}

if (($argv[1] ?? '') === 'snapshot') {
    DB::statement('CREATE TABLE container_probe (id INTEGER PRIMARY KEY, payload VARCHAR(100) NOT NULL)');
    DB::table('container_probe')->insert(['id' => 1, 'payload' => 'original snapshot value']);
    $snapshots = app(DatabaseSnapshot::class);
    $path = $snapshots->newTemporaryPath();
    try {
        $snapshots->dumpTo($path);
        $snapshots->validateSnapshot($path);
        DB::table('container_probe')->where('id', 1)->update(['payload' => 'changed after snapshot']);
        DB::statement('CREATE TABLE container_probe_newer (id INTEGER PRIMARY KEY)');
        $snapshots->restoreFrom($path);
        DB::purge();
        require_container_condition(DB::table('container_probe')->where('id', 1)->value('payload') === 'original snapshot value', 'Native database snapshot round trip failed.');
        require_container_condition(! Schema::hasTable('container_probe_newer'), 'A table missing from the snapshot survived the restore.');
    } finally {
        $snapshots->removeTemporary($path);
        DB::statement('DROP TABLE container_probe');
    }
    echo 'Native snapshot round trip passed: '.DB::connection()->getDriverName().PHP_EOL;

    return;
}

require_container_condition(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 5, 'Expected PHP 8.5.');
foreach (['sodium', 'pcntl', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'gd', 'dom', 'xmlreader', 'simplexml', 'intl', 'mbstring', 'zip'] as $extension) {
    require_container_condition(extension_loaded($extension), 'Missing extension: '.$extension);
}
require_container_condition(gd_info()['WebP Support'] && gd_info()['JPEG Support'], 'Required GD image formats are unavailable.');
require_container_condition(sys_get_temp_dir() === '/app/storage/app/private/tmp', 'Temporary files must use private disk storage.');
require_container_condition((fileperms(sys_get_temp_dir()) & 0777) === 0700, 'Private temporary directory permissions are incorrect.');
require_container_condition(! is_writable('/app/artisan') && ! is_writable('/app/config') && ! is_writable('/app/public'), 'Application code is writable.');
foreach (['.env', '.git', 'node_modules', 'tests', 'docs', 'auth.json'] as $path) {
    require_container_condition(! file_exists('/app/'.$path), 'Unexpected image content: '.$path);
}
foreach (['laravel/boost', 'laravel/pint', 'pestphp/pest', 'fakerphp/faker', 'mockery/mockery'] as $package) {
    require_container_condition(! InstalledVersions::isInstalled($package), 'Development package present: '.$package);
}
require_container_condition(glob('/app/database/seeders/*.php') === [], 'Demo seeders must not ship.');
foreach (['/usr/bin/gcc', '/usr/bin/g++', '/usr/bin/make', '/usr/local/bin/composer', '/usr/local/bin/node', '/usr/local/bin/npm'] as $tool) {
    require_container_condition(! file_exists($tool), 'Build tool present in runtime image: '.$tool);
}
require_container_condition(DB::table('users')->count() === 0, 'Fresh image must not create accounts.');
$build = json_decode(file_get_contents('/app/build.json'), true, flags: JSON_THROW_ON_ERROR);
require_container_condition($build['version'] === config('vaultigen.version') && $build['release_date'] === config('vaultigen.release_date'), 'Source release metadata changed at runtime.');
echo 'Production runtime checks passed.'.PHP_EOL;
