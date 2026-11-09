<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

test('a fresh application boots with initialized installation configuration before facades are registered', function () {
    $storage = sys_get_temp_dir().'/vaultigen-configuration-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($storage.'/app/private', 0700, true);
    $files->makeDirectory($storage.'/framework/views', 0700, true);
    $files->put($storage.'/app/private/installation.json', json_encode([
        'installation_id' => '550e8400-e29b-41d4-a716-446655440077',
        'passkey_secret' => base64_encode(str_repeat('p', 32)),
        'audit_key' => base64_encode(str_repeat('a', 32)),
    ], JSON_THROW_ON_ERROR));

    try {
        $code = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->useStoragePath($argv[1]);
$app->loadEnvironmentFrom('.env.configuration-test-does-not-exist');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('vaultigen.installation_id') !== '550e8400-e29b-41d4-a716-446655440077'
    || config('vaultigen.passkey_secret') !== base64_encode(str_repeat('p', 32))
    || config('vaultigen.audit_key') !== base64_encode(str_repeat('a', 32))) {
    exit(1);
}
echo 'Application booted with installation configuration.';
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, $storage], base_path(), [
            'APP_CONFIG_CACHE' => $storage.'/configuration-cache.php',
            'VAULTIGEN_INSTALLATION_ID' => false,
            'PASSKEYS_USER_HANDLE_SECRET' => false,
            'VAULTIGEN_AUDIT_KEY' => false,
        ]);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        expect($process->getOutput())->toBe('Application booted with installation configuration.');
    } finally {
        $files->deleteDirectory($storage);
    }
});
