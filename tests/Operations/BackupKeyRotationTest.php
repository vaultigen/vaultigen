<?php

use App\Console\Commands\RotateBackupKey;
use App\Services\Operations\BackupService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    Storage::fake('local');
    config(['vaultigen.backup_root' => Storage::disk('local')->path('backups')]);
    Storage::fake('attachments');

    $this->directory = sys_get_temp_dir().'/vaultigen-backup-rotate-'.bin2hex(random_bytes(6));
    mkdir($this->directory, 0700, true);
    $this->keyringPath = $this->directory.'/backup.json';
    file_put_contents($this->keyringPath, json_encode([
        'active_key_id' => 'backup-test-v1',
        'keys' => ['backup-test-v1' => base64_encode(str_repeat('b', 32))],
    ], JSON_THROW_ON_ERROR));

    config([
        'vaultigen.backup_keyring' => null,
        'vaultigen.backup_key_file' => $this->keyringPath,
    ]);
});

afterEach(function (): void {
    @unlink($this->keyringPath);
    @rmdir($this->directory);
});

it('rotates the backup key and re-encrypts every retained backup set', function (): void {
    $fixture = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($fixture['alice'], $fixture['vault']);
    vaultigen_upload_attachment($fixture['alice'], $entry, 'attachment-bytes');

    $run = vaultigen_run_backup($fixture['admin']);
    expect($run->state)->toBe('completed');
    $originalChecksum = $run->manifest_checksum;

    $this->artisan('vaultigen:backup-key:rotate')->assertSuccessful();

    $document = json_decode((string) file_get_contents($this->keyringPath), true, flags: JSON_THROW_ON_ERROR);
    $newKeyId = $document['active_key_id'];

    expect($newKeyId)->not->toBe('backup-test-v1')
        ->and($document['keys'])->toHaveCount(2)
        ->and($document['keys'])->toHaveKey('backup-test-v1');

    $manifest = app(BackupService::class)->readManifest((string) $run->path);
    expect($manifest['backup_key_id'])->toBe($newKeyId);

    $run->refresh();
    expect($run->manifest_checksum)->not->toBe($originalChecksum);

    app(BackupService::class)->verifySet((string) $run->path);
    expect(true)->toBeTrue();
});

it('retires old keys once every retained backup is re-encrypted', function (): void {
    $fixture = vaultigen_entry_fixture();
    $entry = vaultigen_make_entry($fixture['alice'], $fixture['vault']);
    vaultigen_upload_attachment($fixture['alice'], $entry, 'attachment-bytes');

    $run = vaultigen_run_backup($fixture['admin']);

    $this->artisan('vaultigen:backup-key:rotate', ['--retire-old-keys' => true])->assertSuccessful();

    $document = json_decode((string) file_get_contents($this->keyringPath), true, flags: JSON_THROW_ON_ERROR);
    expect($document['keys'])->toHaveCount(1)
        ->and($document['keys'])->not->toHaveKey('backup-test-v1');

    app(BackupService::class)->verifySet((string) $run->path);
    expect(true)->toBeTrue();

    $oldOnly = $this->directory.'/old.json';
    file_put_contents($oldOnly, json_encode([
        'active_key_id' => 'backup-test-v1',
        'keys' => ['backup-test-v1' => base64_encode(str_repeat('b', 32))],
    ], JSON_THROW_ON_ERROR));
    config(['vaultigen.backup_key_file' => $oldOnly]);

    expect(fn () => app(BackupService::class)->verifySet((string) $run->path))
        ->toThrow(RuntimeException::class);

    @unlink($oldOnly);
});

it('keeps old keys when a retained backup cannot be re-encrypted', function (): void {
    $fixture = vaultigen_entry_fixture();
    $run = vaultigen_run_backup($fixture['admin']);

    Storage::disk('local')->put((string) $run->path.'/manifest.mac', 'tampered');

    $this->artisan('vaultigen:backup-key:rotate', ['--retire-old-keys' => true])->assertFailed();

    $document = json_decode((string) file_get_contents($this->keyringPath), true, flags: JSON_THROW_ON_ERROR);
    expect($document['keys'])->toHaveCount(2)
        ->and($document['keys'])->toHaveKey('backup-test-v1');
});

it('refuses to rotate the environment backup keyring', function (): void {
    config([
        'vaultigen.backup_keyring' => json_encode([
            'active_key_id' => 'backup-env-v1',
            'keys' => ['backup-env-v1' => base64_encode(str_repeat('e', 32))],
        ], JSON_THROW_ON_ERROR),
    ]);

    $this->artisan('vaultigen:backup-key:rotate')->assertFailed();
});

it('reports a malformed backup keyring', function (): void {
    file_put_contents($this->keyringPath, 'not-json');

    $this->artisan('vaultigen:backup-key:rotate')->assertFailed();

    file_put_contents($this->keyringPath, json_encode([
        'active_key_id' => 'missing',
        'keys' => ['present' => base64_encode(str_repeat('b', 32))],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('vaultigen:backup-key:rotate')->assertFailed();
});

it('reports a backup keyring that contains an invalid key', function (): void {
    file_put_contents($this->keyringPath, json_encode([
        'active_key_id' => 'good',
        'keys' => [
            'good' => base64_encode(str_repeat('b', 32)),
            'bad' => base64_encode('too-short'),
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('vaultigen:backup-key:rotate')->assertFailed();
});

it('throws when the keyring file cannot be written', function (): void {
    expect(fn () => (new RotateBackupKey)->writeDocument($this->directory, []))
        ->toThrow(RuntimeException::class);
});

it('reports a keyring that cannot be updated before any backup is rewrapped', function (): void {
    $command = new ConfigurableFailingRotateBackupKey;
    $command->failAfter = 0;
    $command->setLaravel($this->app);

    $status = $command->run(new ArrayInput([]), new BufferedOutput);

    expect($status)->toBe(1);
});

it('reports a retained backup whose manifest cannot be read', function (): void {
    $fixture = vaultigen_entry_fixture();
    $run = vaultigen_run_backup($fixture['admin']);

    Storage::disk('local')->delete((string) $run->path.'/manifest.json');

    $this->artisan('vaultigen:backup-key:rotate')->assertFailed();
});

it('reports a retained backup whose encryption key is unavailable', function (): void {
    $fixture = vaultigen_entry_fixture();
    $run = vaultigen_run_backup($fixture['admin']);

    $manifest = app(BackupService::class)->readManifest((string) $run->path);
    $manifest['backup_key_id'] = 'no-longer-present';
    Storage::disk('local')->put((string) $run->path.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    $this->artisan('vaultigen:backup-key:rotate')->assertFailed();
});

it('reports a keyring update failure while retiring old keys', function (): void {
    $fixture = vaultigen_entry_fixture();
    vaultigen_run_backup($fixture['admin']);

    $command = new ConfigurableFailingRotateBackupKey;
    $command->failAfter = 1;
    $command->setLaravel($this->app);

    $status = $command->run(
        new ArrayInput(['--retire-old-keys' => true]),
        new BufferedOutput,
    );

    expect($status)->toBe(1);
});

class ConfigurableFailingRotateBackupKey extends RotateBackupKey
{
    public int $failAfter = 0;

    private int $writes = 0;

    public function writeDocument(string $path, array $document): void
    {
        if ($this->writes++ >= $this->failAfter) {
            throw new RuntimeException('forced write failure');
        }

        parent::writeDocument($path, $document);
    }
}
