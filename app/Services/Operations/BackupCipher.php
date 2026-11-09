<?php

namespace App\Services\Operations;

use App\Services\Crypto\AssociatedData;
use App\Services\Crypto\FileStreamCipher;

/**
 * Encrypts and authenticates a backup set with the backup key.
 *
 * The database snapshot is encrypted with libsodium's secretstream construction,
 * so a multi-gigabyte snapshot streams to disk without ever being held in memory
 * as plaintext. The manifest is authenticated with an HMAC so its inventory and
 * checksums cannot be rewritten without the backup key.
 */
class BackupCipher
{
    public const PURPOSE_SNAPSHOT = 'backup-snapshot';

    public const PURPOSE_MANIFEST = 'backup-manifest';

    public function __construct(private readonly FileStreamCipher $streams) {}

    /**
     * @param  resource  $source
     * @param  resource  $destination
     * @return array{checksum: string, ciphertext_bytes: int, plaintext_bytes: int}
     */
    public function encryptSnapshot($source, $destination, string $backupKeyId, #[\SensitiveParameter] string $key, string $runId): array
    {
        return $this->streams->encryptStream($source, $destination, $key, $this->snapshotAssociatedData($runId, $backupKeyId));
    }

    /**
     * @param  resource  $source
     * @param  resource  $destination
     */
    public function decryptSnapshot($source, $destination, string $backupKeyId, #[\SensitiveParameter] string $key, string $runId): int
    {
        return $this->streams->decryptStream($source, $destination, $key, $this->snapshotAssociatedData($runId, $backupKeyId));
    }

    /**
     * @param  resource  $source
     */
    public function verifySnapshotChecksum($source, string $expected): bool
    {
        return $this->streams->verifyChecksum($source, $expected);
    }

    public function manifestMac(string $backupKeyId, #[\SensitiveParameter] string $key, string $runId, string $canonicalManifest): string
    {
        return hash_hmac('sha256', $this->manifestMacInput($runId, $backupKeyId, $canonicalManifest), $key);
    }

    public function verifyManifestMac(string $backupKeyId, #[\SensitiveParameter] string $key, string $runId, string $canonicalManifest, string $expected): bool
    {
        return hash_equals($this->manifestMac($backupKeyId, $key, $runId, $canonicalManifest), $expected);
    }

    private function manifestMacInput(string $runId, string $backupKeyId, string $canonicalManifest): string
    {
        return 'vaultigen-backup-manifest-v1|'.config('vaultigen.installation_id').'|'.$runId.'|'.$backupKeyId.'|'.hash('sha256', $canonicalManifest);
    }

    private function snapshotAssociatedData(string $runId, string $backupKeyId): string
    {
        return AssociatedData::encode([
            'installation_id' => (string) config('vaultigen.installation_id'),
            'purpose' => self::PURPOSE_SNAPSHOT,
            'backup_run_id' => $runId,
            'backup_key_id' => $backupKeyId,
        ]);
    }
}
