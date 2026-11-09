<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\AuditWriter;
use App\Services\Operations\BackupKeyring;
use App\Services\Operations\BackupKeyRotationService;
use App\Services\Operations\BackupService;
use App\Services\Operations\MaintenanceWindow;
use App\Services\Operations\OperationFailedException;
use App\Services\Operations\OperationLock;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class RotateBackupKey extends Command
{
    protected $signature = 'vaultigen:backup-key:rotate
        {--retire-old-keys : Remove previous keys from the keyring once every retained backup has been re-encrypted}';

    protected $description = 'Add a new backup encryption key and re-encrypt every retained backup set with it';

    public function handle(BackupKeyRotationService $rotation, BackupService $backups, AuditWriter $audit): int
    {
        $lock = new OperationLock;
        $owner = (string) Str::uuid();
        $began = false;
        try {
            $lock->acquire();
            $backups->assertNoConflict();
            app(MaintenanceWindow::class)->begin('backup_key_rotation', $owner, 'Backup key rotation');
            $began = true;

            return $this->rotate($rotation, $backups, $audit);
        } catch (OperationFailedException) {
            $this->error('Backup key rotation refused while another operation is active.');

            return self::FAILURE;
        } finally {
            if ($began) {
                app(MaintenanceWindow::class)->endOwned('backup_key_rotation', $owner);
            }
            $lock->release();
        }
    }

    private function rotate(BackupKeyRotationService $rotation, BackupService $backups, AuditWriter $audit): int
    {
        if (is_string(config('vaultigen.backup_keyring')) && config('vaultigen.backup_keyring') !== '') {
            $this->error('This installation uses the environment backup keyring; add a new key to VAULTIGEN_BACKUP_KEYRING, set its active_key_id, and keep the old key available to re-encrypt retained backups.');

            return self::FAILURE;
        }

        $path = (string) config('vaultigen.backup_key_file');

        try {
            $document = $this->readDocument($path);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $newKeyId = 'backup-'.now()->utc()->format('Ymd').'-'.bin2hex(random_bytes(4));
        $keys = is_array($document['keys'] ?? null) ? $document['keys'] : [];
        $keys[$newKeyId] = base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
        $document['keys'] = $keys;
        $document['active_key_id'] = $newKeyId;

        try {
            $this->writeDocument($path, $document);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $keyring = new BackupKeyring;
        $newKey = $keyring->key($newKeyId);

        $rewrapped = 0;
        $failed = false;

        foreach (BackupRun::query()->where('state', BackupRun::STATE_COMPLETED)->whereNotNull('path')->orderBy('created_at')->get() as $run) {
            $directory = (string) $run->path;

            try {
                $manifest = $backups->readManifest($directory);
            } catch (Throwable) {
                $this->warn("Backup [{$run->id}] has no readable manifest; left unchanged.");
                $failed = true;

                continue;
            }

            $oldKeyId = is_string($manifest['backup_key_id'] ?? null) ? $manifest['backup_key_id'] : '';

            if ($oldKeyId === '' || ! in_array($oldKeyId, $keyring->keyIds(), true)) {
                $this->warn("Backup [{$run->id}] uses unavailable key [{$oldKeyId}]; left unchanged.");
                $failed = true;

                continue;
            }

            try {
                $result = $rotation->rewrap($directory, $oldKeyId, $keyring->key($oldKeyId), $newKeyId, $newKey);
                $run->forceFill([
                    'manifest_checksum' => $result['checksum'],
                    'total_bytes' => $result['total_bytes'],
                    'updated_at' => now(),
                ])->save();
                $rewrapped++;
            } catch (OperationFailedException $exception) {
                $this->warn("Backup [{$run->id}] could not be re-encrypted ({$exception->failureCode}); left unchanged.");
                $failed = true;
            }
        }

        if ($failed) {
            $this->error('Backup key rotated, but not every retained backup could be re-encrypted. Old keys were kept.');

            return self::FAILURE;
        }

        $retired = 0;
        if ($this->option('retire-old-keys')) {
            $retired = count(array_filter($keyring->keyIds(), fn (string $id): bool => $id !== $newKeyId));
            $document['keys'] = [$newKeyId => $keys[$newKeyId]];

            try {
                $this->writeDocument($path, $document);
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        $audit->record('backup.key_rotated', null, 'success', [
            'sanitized_context' => ['new_key_id' => $newKeyId, 'rewrapped' => $rewrapped, 'retired_keys' => $retired],
        ]);

        $this->info("Backup key rotated to [{$newKeyId}]; re-encrypted {$rewrapped} retained set(s).");

        if ($retired > 0) {
            $this->info("Retired {$retired} old key(s). Off-host copies that were not re-encrypted can no longer be restored.");
        } else {
            $this->line('Old keys stay in the keyring. Re-run with --retire-old-keys once every off-host copy is accounted for.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    public function readDocument(string $path): array
    {
        try {
            $document = json_decode((string) @file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The backup keyring is malformed or could not be read.');
        }

        $activeKeyId = $document['active_key_id'] ?? null;
        $keys = $document['keys'] ?? null;
        if (! is_array($keys) || $keys === [] || ! is_string($activeKeyId) || $activeKeyId === '' || ! array_key_exists($activeKeyId, $keys)) {
            throw new RuntimeException('The backup keyring is missing or invalid.');
        }

        foreach ($keys as $keyId => $encoded) {
            $key = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (! is_string($keyId) || $keyId === '' || $key === false
                || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new RuntimeException('The backup keyring contains an invalid key.');
            }
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function writeDocument(string $path, array $document): void
    {
        $mask = umask(0077);
        $written = @file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        umask($mask);

        if ($written === false) {
            throw new RuntimeException('The backup keyring could not be updated.');
        }
    }
}
