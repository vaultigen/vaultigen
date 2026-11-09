<?php

use App\Services\AuditWriter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

test('concurrent processes allocate one continuous verifiable audit chain', function () {
    $path = tempnam(sys_get_temp_dir(), 'vaultigen-audit-');
    $original = config('database.default');
    $database = 'vaultigen_concurrency_'.bin2hex(random_bytes(6));
    $configuration = config('database.connections.'.$original);
    if ($original === 'sqlite') {
        $configuration = [...$configuration, 'database' => $path, 'busy_timeout' => 10000];
    } else {
        config(['database.connections.audit_control' => [...$configuration, 'url' => null]]);
        DB::connection('audit_control')->statement('CREATE DATABASE '.$database);
        $configuration['database'] = $database;
    }
    $configuration['url'] = null;
    config(['database.connections.audit_concurrency' => $configuration]);
    Artisan::call('migrate', ['--database' => 'audit_concurrency', '--force' => true]);
    $processes = [];
    try {
        for ($i = 0; $i < 4; $i++) {
            $code = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'concurrency', 'database.connections.concurrency' => json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR),
    'vaultigen.installation_id' => '550e8400-e29b-41d4-a716-446655440000',
    'vaultigen.audit_key' => base64_encode(str_repeat('a', 32)), 'vaultigen.passkey_secret' => base64_encode(str_repeat('p', 32))]);
for ($i = 0; $i < 10; $i++) { app(App\Services\AuditWriter::class)->record('concurrent.event'); }
CODE;
            $process = new Process([PHP_BINARY, '-r', $code], base_path());
            $process->setInput(json_encode($configuration, JSON_THROW_ON_ERROR));
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        }
        DB::setDefaultConnection('audit_concurrency');
        expect(DB::table('audit_events')->count())->toBe(40);
        expect(app(AuditWriter::class)->verify())->toBeTrue();
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('audit_concurrency');
        if ($original !== 'sqlite') {
            DB::connection('audit_control')->statement('DROP DATABASE '.$database);
            DB::purge('audit_control');
        }
        @unlink($path);
        @unlink($path.'-wal');
        @unlink($path.'-shm');
    }
});
