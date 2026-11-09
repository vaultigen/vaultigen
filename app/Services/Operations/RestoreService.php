<?php

namespace App\Services\Operations;

use App\Contracts\KekProvider;
use App\Models\BackupRun;
use App\Models\KeyRotationRun;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

/**
 * Host-only restore of a backup set into a clean installation.
 *
 * Every check runs before the target database is touched, so a missing key, a
 * failed checksum, or a mismatched installation identity stops the restore with
 * no mutation. The database snapshot is decrypted to a temporary file, handed to
 * the engine's restore tool or copied over the SQLite file, and then runtime
 * state (a stale maintenance window and any in-flight operations inherited from
 * the snapshot) is reset.
 */
final class RestoreService
{
    public function __construct(
        private readonly BackupKeyring $backupKeys,
        private readonly KekProvider $kek,
        private readonly BackupCipher $cipher,
        private readonly DatabaseSnapshot $snapshot,
        private readonly AuditWriter $audit,
        private readonly BackupManifest $manifests,
    ) {}

    /**
     * Verify every recoverable input without changing the destination.
     *
     * @return array<string, mixed> The authenticated manifest.
     *
     * @throws OperationFailedException
     */
    public function preflight(string $directory): array
    {
        $raw = $this->readFile(PrivatePaths::within($directory, 'manifest.json')) ?? throw new OperationFailedException('manifest_missing');
        $mac = $this->readFile(PrivatePaths::within($directory, 'manifest.mac')) ?? throw new OperationFailedException('manifest_missing');

        try {
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OperationFailedException('manifest_malformed');
        }
        if (! is_array($manifest)) {
            throw new OperationFailedException('manifest_malformed');
        }

        $backupKeyId = $this->string($manifest, 'backup_key_id');
        $runId = $this->string($manifest, 'backup_run_id');
        if (! $this->backupKeys->available() || ! in_array($backupKeyId, $this->backupKeys->keyIds(), true)) {
            throw new OperationFailedException('backup_key_missing');
        }
        $backupKey = $this->backupKeys->key($backupKeyId);
        if (! $this->cipher->verifyManifestMac($backupKeyId, $backupKey, $runId, $raw, trim($mac))) {
            throw new OperationFailedException('manifest_authentication_failed');
        }

        if ($this->string($manifest, 'installation_id') !== (string) config('vaultigen.installation_id')) {
            throw new OperationFailedException('installation_mismatch');
        }

        $this->manifests->validate($manifest);
        if ($manifest['database_driver'] !== $this->snapshot->driver()) {
            throw new OperationFailedException('database_driver_mismatch');
        }

        $required = $manifest['required_key_ids'];
        $requiredKek = is_array($required) && is_array($required['kek'] ?? null) ? $required['kek'] : [];
        $availableKek = $this->kek->keyIds();
        foreach ($requiredKek as $keyId) {
            if (! is_string($keyId) || ! in_array($keyId, $availableKek, true)) {
                throw new OperationFailedException('kek_key_missing');
            }
        }

        if (($manifest['app_key_present'] ?? false) === true && ! filled(config('app.key'))) {
            throw new OperationFailedException('app_key_missing');
        }
        if (($manifest['passkey_secret_present'] ?? false) === true && ! filled(config('vaultigen.passkey_secret'))) {
            throw new OperationFailedException('passkey_secret_missing');
        }

        if (! filled(config('vaultigen.audit_key')) || ! in_array(config('vaultigen.audit_key_id'), $required['audit'], true)) {
            throw new OperationFailedException('audit_key_missing');
        }
        $this->manifests->verifyRecovery($manifest);

        $snapshot = $manifest['snapshot'];
        if (! $this->checksumMatches(PrivatePaths::within($directory, (string) ($snapshot['file'] ?? '')), (string) ($snapshot['checksum'] ?? '')) || filesize(PrivatePaths::within($directory, $snapshot['file'])) !== $snapshot['bytes']) {
            throw new OperationFailedException('integrity_failed');
        }

        $objects = $manifest['objects'] ?? [];
        if (is_array($objects)) {
            foreach ($objects as $object) {
                PrivatePaths::within(Storage::disk('attachments')->path(''), $object['source_path']);
                if (! is_array($object) || ! $this->checksumMatches(PrivatePaths::within($directory, (string) ($object['file'] ?? '')), (string) ($object['checksum'] ?? '')) || filesize(PrivatePaths::within($directory, $object['file'])) !== $object['bytes']) {
                    throw new OperationFailedException('integrity_failed');
                }
            }
        }

        return $manifest;
    }

    /**
     * Restore an authenticated set. Preflight runs again so a caller cannot
     * skip verification even if it already checked the set.
     *
     * Plaintext staging is always removed. If application fails after the
     * database is replaced, the restore marker keeps the installation offline
     * and a retry stages again from the authenticated set.
     */
    public function restore(string $directory, bool $servicesStopped = false): void
    {
        if (! $servicesStopped || ! app()->isDownForMaintenance()) {
            throw new OperationFailedException('restore_requires_offline');
        }
        $lock = new OperationLock;
        $lock->acquire(allowRestore: true);
        $stage = null;
        $applying = false;
        $mask = umask(0077);

        try {
            $manifest = $this->preflight($directory);
            $root = PrivatePaths::root(Storage::disk('attachments')->path(''));
            PrivatePaths::space($root, $manifest['total_bytes'] * 2);
            PrivatePaths::space((string) config('vaultigen.backup_temporary_root'), $manifest['total_bytes'] * 3);
            $temporary = $this->snapshot->newTemporaryPath();
            $stage = dirname($temporary);
            $this->decryptSnapshotTo(PrivatePaths::within($directory, $manifest['snapshot']['file']), $temporary, $manifest);
            $this->snapshot->validateSnapshot($temporary);
            foreach ($manifest['objects'] as $index => $object) {
                $destination = PrivatePaths::within($root, $object['source_path']);
                $this->assertWritableTarget($destination);
                $staged = $stage.'/object-'.$index;
                if (! @copy(PrivatePaths::within($directory, $object['file']), $staged)
                    || ! $this->checksumMatches($staged, $object['checksum']) || filesize($staged) !== $object['bytes']) {
                    throw new OperationFailedException('object_unwritable');
                }
            }
            if ($this->snapshot->driver() === 'sqlite') {
                $target = PrivatePaths::file((string) config('database.connections.sqlite.database'));
                $this->assertWritableTarget($target);
                PrivatePaths::space($target, (int) filesize($temporary) * 2);
                $pdo = new \PDO('sqlite:'.$temporary, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);
                $this->assertInventory($manifest, function (string $table) use ($pdo): array {
                    $statement = $pdo->query('SELECT id, random_storage_path FROM '.$table.' WHERE random_storage_path IS NOT NULL');
                    if ($statement === false) {
                        throw new OperationFailedException('inventory_mismatch');
                    }

                    return $statement->fetchAll(\PDO::FETCH_ASSOC);
                });
                $pdo = null;
            }
            $marker = PrivatePaths::file(OperationLock::restoreMarker());
            if (file_put_contents($marker, json_encode(['backup_run_id' => $manifest['backup_run_id']], JSON_THROW_ON_ERROR)) === false) {
                throw new OperationFailedException('object_unwritable');
            }
            $applying = true;
            $this->snapshot->restoreFrom($temporary);
            $this->assertInventory($manifest, fn (string $table): array => DB::table($table)->whereNotNull('random_storage_path')->get(['id', 'random_storage_path'])->map(fn (object $row): array => (array) $row)->all());
            foreach ($manifest['objects'] as $index => $object) {
                $destination = PrivatePaths::within($root, $object['source_path']);
                PrivatePaths::directory(dirname($destination));
                $replacement = $destination.'.restore-'.bin2hex(random_bytes(8));
                if (! @copy($stage.'/object-'.$index, $replacement)
                    || ! $this->checksumMatches($replacement, $object['checksum']) || ! @rename($replacement, $destination)) {
                    @unlink($replacement);
                    throw new OperationFailedException('object_unwritable');
                }
            }
            $this->resetRuntimeState();
            $this->audit->record('restore.completed', null, 'success', [
                'sanitized_context' => ['backup_run_id' => $manifest['backup_run_id']],
            ]);
            if (! unlink($marker)) {
                throw new OperationFailedException('object_unwritable');
            }
            $applying = false;
        } catch (Throwable $exception) {
            if ($applying) {
                throw new OperationFailedException('restore_apply_failed', previous: $exception);
            }
            throw $exception instanceof OperationFailedException ? $exception : new OperationFailedException('restore_staging_failed', previous: $exception);
        } finally {
            if (is_string($stage)) {
                File::deleteDirectory($stage);
            }
            umask($mask);
            $lock->release();
        }
    }

    private function assertWritableTarget(string $path): void
    {
        $parent = dirname($path);
        while (! file_exists($parent) && dirname($parent) !== $parent) {
            $parent = dirname($parent);
        }
        if (! is_dir($parent) || ! is_writable($parent) || (file_exists($path) && (! is_file($path) || ! is_writable($path)))) {
            throw new OperationFailedException('object_unwritable');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  callable(string): array<int, array<string, mixed>>  $rows
     */
    private function assertInventory(array $manifest, callable $rows): void
    {
        $expected = [];
        foreach (['attachment' => 'file_attachments', 'import_source' => 'import_runs'] as $kind => $table) {
            foreach ($rows($table) as $row) {
                $expected[$kind.':'.$row['id']] = $row['random_storage_path'];
            }
        }
        $actual = [];
        foreach ($manifest['objects'] as $object) {
            $actual[$object['kind'].':'.$object['id']] = $object['source_path'];
        }
        ksort($actual);
        ksort($expected);
        if ($actual !== $expected) {
            throw new OperationFailedException('inventory_mismatch');
        }
    }

    /**
     * Decrypt the encrypted snapshot into a plaintext temporary file.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function decryptSnapshotTo(string $sourcePath, string $outputPath, array $manifest): void
    {
        $backupKeyId = $this->string($manifest, 'backup_key_id');
        $backupKey = $this->backupKeys->key($backupKeyId);

        $source = @fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new OperationFailedException('snapshot_unreadable');
        }
        $destination = @fopen($outputPath, 'wb');
        if ($destination === false) {
            fclose($source);

            throw new OperationFailedException('snapshot_unwritable');
        }

        try {
            $this->cipher->decryptSnapshot($source, $destination, $backupKeyId, $backupKey, $this->string($manifest, 'backup_run_id'));
        } catch (Throwable $exception) {
            throw new OperationFailedException('integrity_failed', previous: $exception);
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    /**
     * Clear state that the snapshot may have captured mid-operation.
     */
    public function resetRuntimeState(): void
    {
        DB::table('maintenance_windows')->where('id', 1)->update([
            'active' => false,
            'operation' => null,
            'operation_id' => null,
            'reason' => null,
            'ended_at' => now(),
        ]);

        foreach (['jobs', 'sessions', 'cache', 'cache_locks'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        DB::table('import_runs')->whereIn('status', ['queued', 'importing'])->update([
            'status' => 'failed', 'error_code' => 'restored', 'updated_at' => now(),
        ]);

        $stale = ['error_code' => 'restored', 'failed_at' => now(), 'updated_at' => now()];

        BackupRun::query()
            ->whereIn('state', [BackupRun::STATE_PENDING, BackupRun::STATE_RUNNING])
            ->update([...$stale, 'state' => BackupRun::STATE_FAILED]);

        KeyRotationRun::query()
            ->whereIn('status', [KeyRotationRun::STATUS_PENDING, KeyRotationRun::STATUS_RUNNING])
            ->update([...$stale, 'status' => KeyRotationRun::STATUS_FAILED]);
    }

    private function checksumMatches(string $path, string $expected): bool
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

    private function readFile(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function string(array $manifest, string $key): string
    {
        $value = $manifest[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
