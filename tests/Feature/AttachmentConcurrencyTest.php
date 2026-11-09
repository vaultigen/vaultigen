<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

test('concurrent reservation attempts cannot bypass the vault quota', function () {
    $path = tempnam(sys_get_temp_dir(), 'vaultigen-attachment-');
    $original = config('database.default');
    $database = 'vaultigen_attachment_'.bin2hex(random_bytes(6));
    $configuration = config('database.connections.'.$original);
    if ($original === 'sqlite') {
        $configuration = [...$configuration, 'database' => $path, 'busy_timeout' => 10000];
    } else {
        config(['database.connections.attachment_control' => [...$configuration, 'url' => null]]);
        DB::connection('attachment_control')->statement('CREATE DATABASE '.$database);
        $configuration['database'] = $database;
    }
    $configuration['url'] = null;
    config(['database.connections.attachment_concurrency' => $configuration]);
    Artisan::call('migrate', ['--database' => 'attachment_concurrency', '--force' => true]);

    $processes = [];
    try {
        DB::setDefaultConnection('attachment_concurrency');
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $manager = User::factory()->withTwoFactor()->create();
        $vault = vaultigen_make_vault($admin, $manager);

        $code = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['database.default' => 'concurrency', 'database.connections.concurrency' => $payload['connection'],
    'vaultigen.installation_id' => '550e8400-e29b-41d4-a716-446655440000',
    'vaultigen.audit_key' => base64_encode(str_repeat('a', 32)), 'vaultigen.passkey_secret' => base64_encode(str_repeat('p', 32)),
    'vaultigen.kek_provider' => 'environment', 'vaultigen.kek_keyring' => $payload['keyring'],
    'vaultigen.attachments.max_bytes_per_file' => 1000, 'vaultigen.attachments.max_bytes_per_vault' => 1000]);
$user = App\Models\User::findOrFail($payload['user']);
try {
    app(App\Services\AttachmentQuota::class)->reserve($user, $payload['vault'], 600);
} catch (Illuminate\Validation\ValidationException) {
    // The losing reservation is rejected; that is the behavior under test.
}
CODE;

        for ($i = 0; $i < 2; $i++) {
            $process = new Process([PHP_BINARY, '-r', $code], base_path());
            $process->setInput(json_encode([
                'connection' => $configuration,
                'keyring' => config('vaultigen.kek_keyring'),
                'user' => $manager->id,
                'vault' => $vault->id,
            ], JSON_THROW_ON_ERROR));
            $process->start();
            $processes[] = $process;
        }

        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), (string) $process->getErrorOutput());
        }

        expect(DB::table('attachment_reservations')->where('state', 'reserved')->count())->toBe(1);
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('attachment_concurrency');
        if ($original !== 'sqlite') {
            DB::connection('attachment_control')->statement('DROP DATABASE '.$database);
            DB::purge('attachment_control');
        }
        @unlink($path);
        @unlink($path.'-wal');
        @unlink($path.'-shm');
    }
});
