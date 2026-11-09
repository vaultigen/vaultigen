<?php

use App\Models\User;
use App\Services\VaultAccess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

test('concurrent grant changes leave exactly one active grant per principal', function () {
    $path = tempnam(sys_get_temp_dir(), 'vaultigen-vault-');
    $original = config('database.default');
    $database = 'vaultigen_vault_'.bin2hex(random_bytes(6));
    $configuration = config('database.connections.'.$original);
    if ($original === 'sqlite') {
        $configuration = [...$configuration, 'database' => $path, 'busy_timeout' => 10000];
    } else {
        config(['database.connections.vault_control' => [...$configuration, 'url' => null]]);
        DB::connection('vault_control')->statement('CREATE DATABASE '.$database);
        $configuration['database'] = $database;
    }
    $configuration['url'] = null;
    config(['database.connections.vault_concurrency' => $configuration]);
    Artisan::call('migrate', ['--database' => 'vault_concurrency', '--force' => true]);

    $processes = [];
    try {
        DB::setDefaultConnection('vault_concurrency');
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $manager = User::factory()->withTwoFactor()->create();
        $bob = User::factory()->withTwoFactor()->create();
        $vault = vaultigen_make_vault($admin, $manager);

        foreach (['viewer', 'editor', 'manager'] as $permission) {
            $code = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['database.default' => 'concurrency', 'database.connections.concurrency' => $payload['connection'],
    'vaultigen.installation_id' => '550e8400-e29b-41d4-a716-446655440000',
    'vaultigen.audit_key' => base64_encode(str_repeat('a', 32)), 'vaultigen.passkey_secret' => base64_encode(str_repeat('p', 32)),
    'vaultigen.kek_provider' => 'environment', 'vaultigen.kek_keyring' => $payload['keyring']]);
$manager = App\Models\User::findOrFail($payload['manager']);
app(App\Services\VaultMembership::class)->grant($manager, $payload['vault'], $payload['principal'], $payload['permission']);
CODE;
            $process = new Process([PHP_BINARY, '-r', $code], base_path());
            $process->setInput(json_encode([
                'connection' => $configuration,
                'keyring' => config('vaultigen.kek_keyring'),
                'manager' => $manager->id,
                'vault' => $vault->id,
                'principal' => $bob->principal_id,
                'permission' => $permission,
            ], JSON_THROW_ON_ERROR));
            $process->start();
            $processes[] = $process;
        }

        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), (string) $process->getErrorOutput());
        }

        expect(DB::table('vault_grants')->where('principal_id', $bob->principal_id)->whereNotNull('active_slot')->count())->toBe(1)
            ->and(DB::table('vault_grants')->where('principal_id', $bob->principal_id)->count())->toBe(3)
            ->and(app(VaultAccess::class)->permission($bob->refresh(), $vault->id))->toBeIn(['viewer', 'editor', 'manager']);
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('vault_concurrency');
        if ($original !== 'sqlite') {
            DB::connection('vault_control')->statement('DROP DATABASE '.$database);
            DB::purge('vault_control');
        }
        @unlink($path);
        @unlink($path.'-wal');
        @unlink($path.'-shm');
    }
});
