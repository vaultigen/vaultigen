<?php

namespace App\Services\Operations;

use JsonException;
use Throwable;

/**
 * Re-encrypts a retained backup set under a new backup key.
 *
 * The snapshot is decrypted with the key recorded in the authenticated manifest
 * and re-encrypted with the new key, then the manifest is re-authenticated under
 * that key. The verbatim encrypted objects are left untouched: they are
 * protected by attachment keys, not the backup key.
 *
 * The new snapshot ciphertext is fully verified before the originals are
 * replaced, so a rewrap that fails part-way leaves the previous set on disk.
 */
final class BackupKeyRotationService
{
    public function __construct(private readonly BackupCipher $cipher) {}

    /**
     * @return array{checksum: string, total_bytes: int}
     *
     * @throws OperationFailedException
     */
    public function rewrap(string $directory, string $oldKeyId, #[\SensitiveParameter] string $oldKey, string $newKeyId, #[\SensitiveParameter] string $newKey): array
    {
        $storage = app(BackupStorage::class);
        $directory = $storage->directory($directory);
        $disk = $storage->disk();
        foreach (['manifest.json', 'manifest.mac', BackupService::DATABASE_OBJECT] as $file) {
            PrivatePaths::within($disk->path($directory), $file);
        }
        $manifestPath = $directory.'/manifest.json';
        $macPath = $directory.'/manifest.mac';
        $snapshotObject = BackupService::DATABASE_OBJECT;
        $snapshotPath = $directory.'/'.$snapshotObject;

        $raw = $disk->get($manifestPath);
        $mac = $disk->get($macPath);
        if (! is_string($raw) || ! is_string($mac)) {
            throw new OperationFailedException('manifest_missing');
        }

        $manifest = $this->decode($raw);
        $runId = is_string($manifest['backup_run_id'] ?? null) ? $manifest['backup_run_id'] : '';
        if (! $this->cipher->verifyManifestMac($oldKeyId, $oldKey, $runId, $raw, trim($mac))) {
            throw new OperationFailedException('integrity_failed');
        }

        $snapshot = $manifest['snapshot'] ?? null;
        if (! is_array($snapshot)) {
            throw new OperationFailedException('manifest_malformed');
        }

        $plaintext = app(DatabaseSnapshot::class)->newTemporaryPath();
        $snapshotFullPath = $disk->path($snapshotPath);
        $rewrappedPath = $snapshotFullPath.'.rewrap';

        try {
            $this->decrypt($snapshotFullPath, $plaintext, $oldKeyId, $oldKey, $runId);
            $sealed = $this->encrypt($plaintext, $rewrappedPath, $newKeyId, $newKey, $runId);

            $manifest['backup_key_id'] = $newKeyId;
            $manifest['snapshot']['checksum'] = $sealed['checksum'];
            $manifest['snapshot']['bytes'] = $sealed['ciphertext_bytes'];
            $manifest['total_bytes'] = $sealed['ciphertext_bytes'] + $this->objectBytes($manifest);

            $canonical = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $newMac = $this->cipher->manifestMac($newKeyId, $newKey, $runId, $canonical);

            if (! $this->verifySnapshot($rewrappedPath, $sealed['checksum'])) {
                throw new OperationFailedException('integrity_failed');
            }

            foreach ([$snapshotPath, $manifestPath, $macPath] as $original) {
                if (! $disk->copy($original, $original.'.previous')) {
                    throw new OperationFailedException('object_unwritable');
                }
            }
            if (! @rename($rewrappedPath, $snapshotFullPath)) {
                throw new OperationFailedException('snapshot_unwritable');
            }

            if (! $disk->put($manifestPath, $canonical) || ! $disk->put($macPath, $newMac)
                || $disk->get($manifestPath) !== $canonical || $disk->get($macPath) !== $newMac) {
                throw new OperationFailedException('object_unwritable');
            }

            foreach ([$snapshotPath, $manifestPath, $macPath] as $original) {
                $disk->delete($original.'.previous');
            }

            return ['checksum' => hash('sha256', $canonical), 'total_bytes' => (int) $manifest['total_bytes']];
        } catch (OperationFailedException $exception) {
            foreach ([$snapshotPath, $manifestPath, $macPath] as $original) {
                if ($disk->exists($original.'.previous')) {
                    $disk->copy($original.'.previous', $original);
                }
            }
            throw $exception;
        } catch (Throwable $exception) {
            throw new OperationFailedException('integrity_failed', previous: $exception);
        } finally {
            app(DatabaseSnapshot::class)->removeTemporary($plaintext);
            @unlink($rewrappedPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw): array
    {
        try {
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OperationFailedException('manifest_malformed');
        }

        if (! is_array($manifest)) {
            throw new OperationFailedException('manifest_malformed');
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function objectBytes(array $manifest): int
    {
        $bytes = 0;
        foreach (is_array($manifest['objects'] ?? null) ? $manifest['objects'] : [] as $object) {
            if (is_array($object) && is_numeric($object['bytes'] ?? null)) {
                $bytes += (int) $object['bytes'];
            }
        }

        return $bytes;
    }

    /**
     * @throws OperationFailedException
     */
    private function decrypt(string $sourcePath, string $outputPath, string $keyId, #[\SensitiveParameter] string $key, string $runId): void
    {
        $source = @fopen($sourcePath, 'rb');
        $destination = @fopen($outputPath, 'wb');
        if ($source === false || $destination === false) {
            foreach ([$source, $destination] as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            throw new OperationFailedException('snapshot_unreadable');
        }

        try {
            $this->cipher->decryptSnapshot($source, $destination, $keyId, $key, $runId);
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    /**
     * @return array{checksum: string, ciphertext_bytes: int, plaintext_bytes: int}
     *
     * @throws OperationFailedException
     */
    private function encrypt(string $sourcePath, string $outputPath, string $keyId, #[\SensitiveParameter] string $key, string $runId): array
    {
        $source = @fopen($sourcePath, 'rb');
        $destination = @fopen($outputPath, 'wb');
        if ($source === false || $destination === false) {
            foreach ([$source, $destination] as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            throw new OperationFailedException('snapshot_unwritable');
        }

        try {
            return $this->cipher->encryptSnapshot($source, $destination, $keyId, $key, $runId);
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    private function verifySnapshot(string $path, string $expected): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            return $this->cipher->verifySnapshotChecksum($handle, $expected);
        } finally {
            fclose($handle);
        }
    }
}
