<?php

use App\Models\User;
use App\Services\Operations\BackupCipher;
use App\Services\Operations\BackupKeyRotationService;
use App\Services\Operations\BackupService;
use App\Services\Operations\OperationFailedException;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\FakeBackupRuntime;

beforeEach(function (): void {
    Storage::fake('local');
    config(['vaultigen.backup_root' => Storage::disk('local')->path('backups')]);

    $this->dir = 'backups/rewrap-'.bin2hex(random_bytes(4));
    Storage::disk('local')->makeDirectory($this->dir);
    $this->oldKeyId = 'old-key';
    $this->oldKey = str_repeat('a', 32);
    $this->newKeyId = 'new-key';
    $this->newKey = str_repeat('b', 32);
    $this->runId = 'run-1';
    $this->service = app(BackupKeyRotationService::class);
});

function rewrap_put(string $dir, string $raw, string $mac): void
{
    Storage::disk('local')->put($dir.'/manifest.json', $raw);
    Storage::disk('local')->put($dir.'/manifest.mac', $mac);
}

function rewrap_call(object $test): array
{
    return $test->service->rewrap($test->dir, $test->oldKeyId, $test->oldKey, $test->newKeyId, $test->newKey);
}

it('rejects a set whose manifest or mac is missing', function (): void {
    Storage::disk('local')->put($this->dir.'/manifest.json', '{}');

    expect(fn () => rewrap_call($this))->toThrow(OperationFailedException::class);
});

it('rejects malformed manifest json', function (): void {
    rewrap_put($this->dir, 'not-json', 'irrelevant');

    expect(fn () => rewrap_call($this))->toThrow(OperationFailedException::class);
});

it('rejects a manifest that is not an object', function (): void {
    rewrap_put($this->dir, '"just a string"', 'irrelevant');

    expect(fn () => rewrap_call($this))->toThrow(OperationFailedException::class);
});

it('rejects a manifest without a snapshot description', function (): void {
    $cipher = app(BackupCipher::class);
    $raw = json_encode([
        'backup_run_id' => $this->runId,
        'backup_key_id' => $this->oldKeyId,
        'objects' => [],
        'total_bytes' => 0,
    ], JSON_THROW_ON_ERROR);
    rewrap_put($this->dir, $raw, $cipher->manifestMac($this->oldKeyId, $this->oldKey, $this->runId, $raw));

    expect(fn () => rewrap_call($this))->toThrow(OperationFailedException::class);
});

it('fails when the snapshot file cannot be opened for reading', function (): void {
    $cipher = app(BackupCipher::class);
    $raw = json_encode([
        'backup_run_id' => $this->runId,
        'backup_key_id' => $this->oldKeyId,
        'snapshot' => ['file' => 'database.enc', 'checksum' => 'missing', 'bytes' => 1],
        'objects' => [],
        'total_bytes' => 1,
    ], JSON_THROW_ON_ERROR);
    rewrap_put($this->dir, $raw, $cipher->manifestMac($this->oldKeyId, $this->oldKey, $this->runId, $raw));

    expect(fn () => rewrap_call($this))->toThrow(OperationFailedException::class);
});

it('fails when the rewrapped snapshot cannot be written', function (): void {
    $cipher = app(BackupCipher::class);
    $plaintext = sys_get_temp_dir().'/rewrap-plain-'.bin2hex(random_bytes(4));
    file_put_contents($plaintext, 'snapshot bytes');

    $snapshotPath = Storage::disk('local')->path($this->dir.'/database.enc');
    $source = fopen($plaintext, 'rb');
    $destination = fopen($snapshotPath, 'wb');
    $sealed = $cipher->encryptSnapshot($source, $destination, $this->oldKeyId, $this->oldKey, $this->runId);
    fclose($source);
    fclose($destination);
    @unlink($plaintext);

    $raw = json_encode([
        'backup_run_id' => $this->runId,
        'backup_key_id' => $this->oldKeyId,
        'snapshot' => ['file' => 'database.enc', 'checksum' => $sealed['checksum'], 'bytes' => $sealed['ciphertext_bytes']],
        'objects' => [],
        'total_bytes' => $sealed['ciphertext_bytes'],
    ], JSON_THROW_ON_ERROR);
    rewrap_put($this->dir, $raw, $cipher->manifestMac($this->oldKeyId, $this->oldKey, $this->runId, $raw));

    Storage::disk('local')->makeDirectory($this->dir.'/database.enc.rewrap');

    expect(fn () => rewrap_call($this))->toThrow(OperationFailedException::class);
});

it('wraps an unexpected decryption failure as an integrity error', function (): void {
    $cipher = Mockery::mock(BackupCipher::class);
    $cipher->shouldReceive('verifyManifestMac')->andReturn(true);
    $cipher->shouldReceive('decryptSnapshot')->andThrow(new RuntimeException('forced'));

    Storage::disk('local')->put($this->dir.'/database.enc', 'anything');
    rewrap_put($this->dir, '{"snapshot":{}}', 'irrelevant');

    $service = new BackupKeyRotationService($cipher);

    expect(fn () => $service->rewrap($this->dir, $this->oldKeyId, $this->oldKey, $this->newKeyId, $this->newKey))
        ->toThrow(OperationFailedException::class);
});

it('fails when the rewrapped snapshot cannot be verified', function (): void {
    $cipher = Mockery::mock(BackupCipher::class);
    $cipher->shouldReceive('verifyManifestMac')->andReturn(true);
    $cipher->shouldReceive('decryptSnapshot')->andReturn(5);
    $cipher->shouldReceive('manifestMac')->andReturn('mac');
    $cipher->shouldReceive('encryptSnapshot')->andReturnUsing(function ($source, $destination): array {
        $meta = stream_get_meta_data($destination);
        @unlink($meta['uri']);

        return ['checksum' => 'deadbeef', 'ciphertext_bytes' => 1, 'plaintext_bytes' => 5];
    });

    Storage::disk('local')->put($this->dir.'/database.enc', 'anything');
    rewrap_put($this->dir, '{"snapshot":{}}', 'irrelevant');

    $service = new BackupKeyRotationService($cipher);

    expect(fn () => $service->rewrap($this->dir, $this->oldKeyId, $this->oldKey, $this->newKeyId, $this->newKey))
        ->toThrow(OperationFailedException::class);
});

it('fails when the verified snapshot cannot replace the original', function (): void {
    $cipher = Mockery::mock(BackupCipher::class);
    $cipher->shouldReceive('verifyManifestMac')->andReturn(true);
    $cipher->shouldReceive('decryptSnapshot')->andReturn(0);
    $cipher->shouldReceive('manifestMac')->andReturn('mac');
    $cipher->shouldReceive('encryptSnapshot')->andReturn(['checksum' => 'deadbeef', 'ciphertext_bytes' => 1, 'plaintext_bytes' => 1]);
    $cipher->shouldReceive('verifySnapshotChecksum')->andReturn(true);

    Storage::disk('local')->makeDirectory($this->dir.'/database.enc');
    Storage::disk('local')->put($this->dir.'/database.enc.rewrap', 'rewrapped');
    rewrap_put($this->dir, '{"snapshot":{}}', 'irrelevant');

    $service = new BackupKeyRotationService($cipher);

    expect(fn () => $service->rewrap($this->dir, $this->oldKeyId, $this->oldKey, $this->newKeyId, $this->newKey))
        ->toThrow(OperationFailedException::class);
});

it('preserves the original verified backup when replacement fails', function (string $failure): void {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    config(['vaultigen.backup_keyring' => json_encode([
        'active_key_id' => $this->oldKeyId, 'keys' => [$this->oldKeyId => base64_encode($this->oldKey)],
    ], JSON_THROW_ON_ERROR)]);
    $run = vaultigen_run_backup($admin);
    $this->dir = (string) $run->path;
    $directory = vaultigen_backup_directory($run);
    $checksums = array_map(fn (string $file): string => hash_file('sha256', $directory.'/'.$file), ['manifest.json', 'manifest.mac', 'database.enc']);
    if ($failure === 'rename') {
        FakeBackupRuntime::$overrides['rename'] = fn (string $from, string $to): bool => false;
    } else {
        FakeBackupRuntime::refuseStorage('put', fn (string $path): bool => str_ends_with($path, '/manifest.mac'));
    }

    expect(fn () => rewrap_call($this))->toThrow(OperationFailedException::class);

    expect(array_map(fn (string $file): string => hash_file('sha256', $directory.'/'.$file), ['manifest.json', 'manifest.mac', 'database.enc']))->toBe($checksums);
    app(BackupService::class)->verifySet((string) $run->path);
})->with(['rename', 'manifest write']);
