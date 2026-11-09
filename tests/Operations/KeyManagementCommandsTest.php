<?php

use App\Console\Commands\AddKek;
use App\Services\Operations\BackupKeyring;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['vaultigen.backup_root' => Storage::disk('local')->path('backups')]);
    Storage::fake('attachments');
});

it('resolves a backup keyring from the environment', function (): void {
    config([
        'vaultigen.backup_keyring' => json_encode([
            'active_key_id' => 'backup-env-v1',
            'keys' => ['backup-env-v1' => base64_encode(str_repeat('e', 32))],
        ], JSON_THROW_ON_ERROR),
        'vaultigen.backup_key_file' => '/nonexistent/backup.json',
    ]);

    $keyring = new BackupKeyring;

    expect($keyring->available())->toBeTrue()
        ->and($keyring->activeKeyId())->toBe('backup-env-v1')
        ->and($keyring->keyIds())->toBe(['backup-env-v1'])
        ->and(strlen($keyring->key('backup-env-v1')))->toBe(32);
});

it('resolves a backup keyring from a protected file', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'vaultigen-backup-key-');
    file_put_contents($path, json_encode([
        'active_key_id' => 'backup-file-v1',
        'keys' => ['backup-file-v1' => base64_encode(str_repeat('f', 32))],
    ], JSON_THROW_ON_ERROR));
    config(['vaultigen.backup_keyring' => null, 'vaultigen.backup_key_file' => $path]);

    $keyring = new BackupKeyring;

    expect($keyring->available())->toBeTrue()
        ->and($keyring->activeKeyId())->toBe('backup-file-v1');

    @unlink($path);
});

it('reports a missing backup keyring as unavailable', function (): void {
    config(['vaultigen.backup_keyring' => null, 'vaultigen.backup_key_file' => '/nonexistent/backup.json']);

    $keyring = new BackupKeyring;

    expect($keyring->available())->toBeFalse();
    expect(fn () => $keyring->activeKeyId())->toThrow(RuntimeException::class);
});

it('generates the backup keyring once', function (): void {
    $directory = sys_get_temp_dir().'/vaultigen-backup-'.bin2hex(random_bytes(6));
    $path = $directory.'/backup.json';
    config(['vaultigen.backup_keyring' => null, 'vaultigen.backup_key_file' => $path]);

    $this->artisan('vaultigen:backup-key:generate')->assertSuccessful();
    expect(is_file($path))->toBeTrue();

    $this->artisan('vaultigen:backup-key:generate')->assertFailed();
    $this->artisan('vaultigen:backup-key:generate', ['--if-missing' => true])->assertSuccessful();

    @unlink($path);
    @rmdir($directory);
});

it('reports a backup keyring write failure', function (): void {
    $directory = sys_get_temp_dir().'/vaultigen-backup-dir-'.bin2hex(random_bytes(6));
    mkdir($directory);
    config(['vaultigen.backup_keyring' => null, 'vaultigen.backup_key_file' => $directory]);

    $this->artisan('vaultigen:backup-key:generate')->assertFailed();

    @rmdir($directory);
});

it('does not generate a file when an environment backup keyring exists', function (): void {
    config(['vaultigen.backup_keyring' => json_encode([
        'active_key_id' => 'backup-env-v1',
        'keys' => ['backup-env-v1' => base64_encode(str_repeat('e', 32))],
    ], JSON_THROW_ON_ERROR)]);

    $this->artisan('vaultigen:backup-key:generate', ['--if-missing' => true])->assertSuccessful();
    $this->artisan('vaultigen:backup-key:generate')->assertFailed();
});

it('adds a new installation KEK and makes it active', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'vaultigen-kek-');
    file_put_contents($path, json_encode([
        'active_key_id' => 'kek-file-v1',
        'keys' => ['kek-file-v1' => base64_encode(str_repeat('k', 32))],
    ], JSON_THROW_ON_ERROR));
    config(['vaultigen.kek_provider' => 'file', 'vaultigen.kek_file' => $path]);

    $this->artisan('vaultigen:kek:add')->assertSuccessful();

    $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($document['keys'])->toHaveCount(2)
        ->and($document['active_key_id'])->not->toBe('kek-file-v1');

    @unlink($path);
});

it('refuses to add a KEK when the environment provider is configured', function (): void {
    config(['vaultigen.kek_provider' => 'environment']);

    $this->artisan('vaultigen:kek:add')->assertFailed();
});

it('reports a write failure from the KEK keyring writer', function (): void {
    $directory = sys_get_temp_dir().'/vaultigen-kekdir-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);

    expect(fn () => (new AddKek)->writeDocument($directory, ['keys' => []]))
        ->toThrow(RuntimeException::class);

    @rmdir($directory);
});

it('refuses to add a KEK when the keyring is malformed', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'vaultigen-kek-');
    file_put_contents($path, 'not-json');
    config(['vaultigen.kek_provider' => 'file', 'vaultigen.kek_file' => $path]);

    $this->artisan('vaultigen:kek:add')->assertFailed();

    file_put_contents($path, '"scalar"');
    $this->artisan('vaultigen:kek:add')->assertFailed();

    @unlink($path);
});
