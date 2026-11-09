<?php

test('the kek command creates a protected keyring once', function () {
    $directory = sys_get_temp_dir().'/vaultigen-kek-'.bin2hex(random_bytes(6));
    $path = $directory.'/kek.json';
    config(['vaultigen.kek_provider' => 'file', 'vaultigen.kek_file' => $path]);

    try {
        $this->artisan('vaultigen:kek:generate')->assertSuccessful();
        expect($path)->toBeFile()
            ->and(fileperms($path) & 0777)->toBe(0600);

        $keyring = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        expect(strlen(base64_decode($keyring['keys'][$keyring['active_key_id']], true)))->toBe(32);

        $this->artisan('vaultigen:kek:generate')->assertFailed();
        $this->artisan('vaultigen:kek:generate', ['--if-missing' => true])->assertSuccessful();
    } finally {
        @unlink($path);
        @rmdir($directory);
    }
});

test('the kek command respects the environment provider and reports write failures', function () {
    config(['vaultigen.kek_provider' => 'environment']);
    $this->artisan('vaultigen:kek:generate')->assertFailed();
    $this->artisan('vaultigen:kek:generate', ['--if-missing' => true])->assertSuccessful();

    $path = sys_get_temp_dir().'/vaultigen-kek-'.bin2hex(random_bytes(6));
    mkdir($path);
    config(['vaultigen.kek_provider' => 'file', 'vaultigen.kek_file' => $path]);

    try {
        $this->artisan('vaultigen:kek:generate')->assertFailed();
    } finally {
        rmdir($path);
    }
});

test('initialization creates the protected installation identity and keyring together', function () {
    $directory = sys_get_temp_dir().'/vaultigen-init-'.bin2hex(random_bytes(6));
    config([
        'vaultigen.installation_file' => $directory.'/installation.json',
        'vaultigen.kek_file' => $directory.'/kek.json',
        'vaultigen.kek_provider' => 'file',
        'vaultigen.installation_id' => null,
    ]);

    try {
        $this->artisan('vaultigen:initialize')->assertSuccessful();
        expect($directory.'/installation.json')->toBeFile()
            ->and($directory.'/kek.json')->toBeFile()
            ->and(fileperms($directory.'/kek.json') & 0777)->toBe(0600);
    } finally {
        @unlink($directory.'/installation.json');
        @unlink($directory.'/kek.json');
        @rmdir($directory);
    }
});
