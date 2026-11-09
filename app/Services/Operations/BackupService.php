<?php

namespace App\Services\Operations;

use App\Contracts\KekProvider;
use App\Models\BackupRun;
use App\Models\ImportRun;
use App\Models\KeyRotationRun;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Creates, writes, verifies, and reports a complete data-backup set.
 *
 * A set contains the encrypted database snapshot, a verbatim copy of every
 * referenced encrypted object (attachments and staged import sources), and a
 * manifest that records checksums, key identifiers, and versions. The manifest
 * is authenticated and the snapshot is encrypted with the backup key, which is
 * independent from the live installation KEK.
 *
 * The whole operation runs inside a maintenance window so the database snapshot
 * and the object inventory describe the same moment. A partial or unverifiable
 * set is deleted and the run is marked failed; the UI only reports completion
 * after the written files have been re-read and verified.
 */
final class BackupService
{
    public const PREFIX = 'backups';

    public const FORMAT_VERSION = 2;

    public const DATABASE_OBJECT = 'database.enc';

    public function __construct(
        private readonly BackupKeyring $backupKeys,
        private readonly KekProvider $kek,
        private readonly BackupCipher $cipher,
        private readonly DatabaseSnapshot $snapshot,
        private readonly MaintenanceWindow $maintenance,
        private readonly AuditWriter $audit,
        private readonly BackupStorage $storage,
        private readonly BackupManifest $manifests,
    ) {}

    /**
     * Validate that a backup may start and create its non-secret status row.
     *
     * @throws OperationFailedException
     */
    public function start(?User $actor, string $trigger): BackupRun
    {
        $lock = new OperationLock;
        $lock->acquire();
        try {
            return $this->createRun($actor, $trigger);
        } finally {
            $lock->release();
        }
    }

    private function createRun(?User $actor, string $trigger): BackupRun
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('manage-installation');
        }
        $this->assertNoConflict();

        // Fail before creating a run or opening the maintenance window when the
        // backup key is missing, rather than writing a failed run and briefly
        // quiescing the installation.
        if (! $this->backupKeys->available()) {
            throw new OperationFailedException('backup_key_missing');
        }

        $run = BackupRun::query()->create([
            'state' => BackupRun::STATE_PENDING,
            'trigger' => $trigger,
            'requested_by_user_id' => $actor?->id,
            'database_driver' => $this->snapshot->driver(),
        ]);

        $this->audit->record('backup.started', $actor?->id, 'success', [
            'sanitized_context' => ['backup_run_id' => (string) $run->id, 'trigger' => $trigger],
        ]);

        return $run;
    }

    /**
     * @throws OperationFailedException
     */
    public function assertNoConflict(): void
    {
        if ($this->maintenance->active()) {
            throw new OperationFailedException('maintenance_active');
        }
        if (BackupRun::query()->whereIn('state', [BackupRun::STATE_PENDING, BackupRun::STATE_RUNNING])->exists()) {
            throw new OperationFailedException('backup_in_progress');
        }
        if (KeyRotationRun::query()->whereIn('status', [KeyRotationRun::STATUS_PENDING, KeyRotationRun::STATUS_RUNNING])->exists()) {
            throw new OperationFailedException('rotation_in_flight');
        }
        if (ImportRun::query()->whereIn('status', [ImportRun::STATUS_QUEUED, ImportRun::STATUS_IMPORTING])->exists()) {
            throw new OperationFailedException('import_in_flight');
        }
    }

    /**
     * Execute a pending run. Safe to call twice: only the first claim proceeds.
     */
    public function run(BackupRun $run): void
    {
        $lock = new OperationLock;
        $lock->acquire();
        try {
            $this->execute($run);
        } finally {
            $lock->release();
        }
    }

    private function execute(BackupRun $run): void
    {
        $claimed = BackupRun::query()
            ->whereKey($run->id)
            ->where('state', BackupRun::STATE_PENDING)
            ->update(['state' => BackupRun::STATE_RUNNING, 'started_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $run->refresh();
        $began = false;
        $temporary = null;

        try {
            $this->maintenance->begin('backup', (string) $run->id, 'Database backup');
            $began = true;

            // The job may start long after assertNoConflict(), and an import
            // queued meanwhile would make the set impossible to restore.
            if (ImportRun::query()->whereIn('status', [ImportRun::STATUS_QUEUED, ImportRun::STATUS_IMPORTING])->exists()) {
                throw new OperationFailedException('import_in_flight');
            }

            $backupKeyId = $this->backupKeys->activeKeyId();
            $backupKey = $this->backupKeys->key($backupKeyId);
            $directory = $this->storage->directory((string) $run->id);
            if (! $this->storage->disk()->makeDirectory($directory)) {
                throw new OperationFailedException('object_unwritable');
            }
            PrivatePaths::within($this->storage->disk()->path(''), $directory);
            $estimate = $this->snapshot->estimatedBytes();
            PrivatePaths::space($this->storage->disk()->path(''), $estimate * 2 + $this->objectBytes());
            PrivatePaths::space((string) config('vaultigen.backup_temporary_root'), $estimate * 2);

            $temporary = $this->snapshot->newTemporaryPath();
            $this->snapshot->dumpTo($temporary);
            $snapshot = $this->writeEncryptedSnapshot($directory, $temporary, $run, $backupKeyId, $backupKey);
            $objects = $this->copyObjects($directory);

            $manifest = $this->buildManifest($run, $backupKeyId, $snapshot, $objects);
            $canonical = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (! $this->storage->disk()->put($directory.'/manifest.json', $canonical)) {
                throw new OperationFailedException('object_unwritable');
            }
            if (! $this->storage->disk()->put($directory.'/manifest.mac', $this->cipher->manifestMac($backupKeyId, $backupKey, (string) $run->id, $canonical))) {
                throw new OperationFailedException('object_unwritable');
            }

            $this->verify($directory, (string) $run->id, $backupKeyId, $backupKey, $manifest);

            $run->forceFill([
                'state' => BackupRun::STATE_COMPLETED,
                'path' => self::PREFIX.'/'.$directory,
                'manifest_checksum' => hash('sha256', $canonical),
                'object_count' => count($objects),
                'total_bytes' => $manifest['total_bytes'],
                'app_version' => $manifest['app_version'],
                'schema_version' => $manifest['schema_version'],
                'completed_at' => now(),
                'updated_at' => now(),
            ])->save();

            $this->audit->record('backup.completed', $run->requested_by_user_id, 'success', [
                'sanitized_context' => ['backup_run_id' => (string) $run->id, 'objects' => count($objects)],
            ]);
        } catch (Throwable $exception) {
            $code = $exception instanceof OperationFailedException ? $exception->failureCode : 'backup_failed';
            $this->fail($run, $code);
        } finally {
            if (is_string($temporary)) {
                $this->snapshot->removeTemporary($temporary);
            }
            if ($began) {
                $this->maintenance->endOwned('backup', (string) $run->id);
            }
        }
    }

    /**
     * Verify an existing set on disk: manifest authentication and every
     * checksum. Used by the restore preflight and available to operators who
     * want to re-check an old backup.
     */
    public function verifySet(string $directory): void
    {
        $directory = $this->storage->directory($directory);
        $manifest = $this->readManifest($directory);
        $backupKeyId = is_string($manifest['backup_key_id'] ?? null) ? $manifest['backup_key_id'] : '';
        $backupKey = $this->backupKeys->key($backupKeyId);
        $this->verify($directory, is_string($manifest['backup_run_id'] ?? null) ? $manifest['backup_run_id'] : '', $backupKeyId, $backupKey, $manifest);
    }

    /**
     * The decoded manifest of a backup directory.
     *
     * @return array<string, mixed>
     */
    public function readManifest(string $directory): array
    {
        $directory = $this->storage->directory($directory);
        PrivatePaths::within($this->storage->path($directory), 'manifest.json');
        $raw = $this->storage->disk()->get($directory.'/manifest.json');
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('The backup manifest is missing.');
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : throw new RuntimeException('The backup manifest is malformed.');
    }

    /**
     * @return array{file: string, checksum: string, bytes: int}
     */
    private function writeEncryptedSnapshot(string $directory, string $temporary, BackupRun $run, string $backupKeyId, #[\SensitiveParameter] string $backupKey): array
    {
        $source = @fopen($temporary, 'rb');
        $destination = @fopen(PrivatePaths::within($this->storage->path($directory), self::DATABASE_OBJECT), 'wb');
        if ($source === false || $destination === false) {
            if ($source !== false) {
                fclose($source);
            }
            if ($destination !== false) {
                fclose($destination);
            }

            throw new OperationFailedException('snapshot_unreadable');
        }

        try {
            $sealed = $this->cipher->encryptSnapshot($source, $destination, $backupKeyId, $backupKey, (string) $run->id);
        } finally {
            fclose($source);
            fclose($destination);
        }

        return ['file' => self::DATABASE_OBJECT, 'checksum' => $sealed['checksum'], 'bytes' => $sealed['ciphertext_bytes']];
    }

    /**
     * Copy every referenced encrypted object verbatim and record its checksum.
     *
     * @return list<array{kind: string, id: string, file: string, source_path: string, bytes: int, checksum: string}>
     */
    private function copyObjects(string $directory): array
    {
        $objects = [];
        foreach ($this->inventory() as $item) {
            $file = 'objects/'.hash('sha256', $item['kind'].':'.$item['id']).'.enc';
            $objects[] = [
                'kind' => $item['kind'],
                'id' => $item['id'],
                'file' => $file,
                'source_path' => $item['source_path'],
                ...$this->copyObject($item['source_path'], $directory.'/'.$file),
            ];
        }

        return $objects;
    }

    /**
     * @return list<array{kind: string, id: string, source_path: string}>
     */
    private function inventory(): array
    {
        $items = [];
        foreach (DB::table('file_attachments')->whereNotNull('random_storage_path')->orderBy('id')->get(['id', 'random_storage_path']) as $row) {
            $items[] = ['kind' => 'attachment', 'id' => (string) $row->id, 'source_path' => (string) $row->random_storage_path];
        }
        foreach (DB::table('import_runs')->whereNotNull('random_storage_path')->orderBy('id')->get(['id', 'random_storage_path']) as $row) {
            $items[] = ['kind' => 'import_source', 'id' => (string) $row->id, 'source_path' => (string) $row->random_storage_path];
        }

        return $items;
    }

    /**
     * @return array{bytes: int, checksum: string}
     */
    private function copyObject(string $sourcePath, string $destinationPath): array
    {
        $disk = Storage::disk('attachments');
        PrivatePaths::relative($sourcePath);
        if (! $disk->exists($sourcePath)) {
            throw new OperationFailedException('object_missing');
        }

        PrivatePaths::within($this->storage->disk()->path(''), $destinationPath);
        if (! $this->storage->disk()->makeDirectory(dirname($destinationPath))) {
            throw new OperationFailedException('object_unwritable');
        }

        $destination = @fopen($this->storage->disk()->path($destinationPath), 'wb');
        if ($destination === false) {
            throw new OperationFailedException('object_unwritable');
        }

        PrivatePaths::within($disk->path(''), $sourcePath);
        $source = $disk->readStream($sourcePath);
        if (! is_resource($source)) {
            fclose($destination);

            throw new OperationFailedException('object_unreadable');
        }

        $checksum = hash_init('sha256');
        $bytes = 0;
        try {
            while (! feof($source)) {
                $chunk = fread($source, 65536);
                if ($chunk === false || ($chunk === '' && ! feof($source))) {
                    throw new OperationFailedException('object_unreadable');
                }
                hash_update($checksum, $chunk);
                if (fwrite($destination, $chunk) !== strlen($chunk)) {
                    throw new OperationFailedException('object_unwritable');
                }
                $bytes += strlen($chunk);
            }
        } finally {
            fclose($source);
            fclose($destination);
        }

        return ['bytes' => $bytes, 'checksum' => hash_final($checksum)];
    }

    /**
     * @param  array{file: string, checksum: string, bytes: int}  $snapshot
     * @param  list<array{kind: string, id: string, file: string, source_path: string, bytes: int, checksum: string}>  $objects
     * @return array<string, mixed>
     */
    private function buildManifest(BackupRun $run, string $backupKeyId, array $snapshot, array $objects): array
    {
        $totalBytes = $snapshot['bytes'];
        foreach ($objects as $object) {
            $totalBytes += $object['bytes'];
        }

        return [
            'format_version' => self::FORMAT_VERSION,
            'installation_id' => (string) config('vaultigen.installation_id'),
            'backup_run_id' => (string) $run->id,
            'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'app_version' => (string) config('vaultigen.version'),
            'schema_version' => $this->schemaVersion(),
            'database_driver' => $this->snapshot->driver(),
            'backup_key_id' => $backupKeyId,
            'snapshot' => $snapshot,
            'required_key_ids' => [
                'kek' => $this->referencedKekKeyIds(),
                'audit' => [config('vaultigen.audit_key_id')],
            ],
            'app_key_present' => filled(config('app.key')),
            'passkey_secret_present' => filled(config('vaultigen.passkey_secret')),
            'recovery_checks' => $this->manifests->recoveryChecks($this->referencedKekKeyIds()),
            'objects' => $objects,
            'object_count' => count($objects),
            'total_bytes' => $totalBytes,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function verify(string $directory, string $runId, string $backupKeyId, #[\SensitiveParameter] string $backupKey, array $manifest): void
    {
        $directory = $this->storage->directory($directory);
        $disk = $this->storage->disk();
        PrivatePaths::within($disk->path($directory), 'manifest.json');
        PrivatePaths::within($disk->path($directory), 'manifest.mac');
        $raw = $disk->get($directory.'/manifest.json');
        $mac = $disk->get($directory.'/manifest.mac');
        if (! is_string($raw) || ! is_string($mac)
            || ! $this->cipher->verifyManifestMac($backupKeyId, $backupKey, $runId, $raw, trim($mac))) {
            throw new OperationFailedException('integrity_failed');
        }

        $this->manifests->validate($manifest);
        $snapshot = $manifest['snapshot'];
        $snapshotPath = PrivatePaths::within($disk->path($directory), $snapshot['file']);
        if (! $this->verifyFile($snapshotPath, (string) $snapshot['checksum']) || filesize($snapshotPath) !== $snapshot['bytes']) {
            throw new OperationFailedException('integrity_failed');
        }

        foreach ($manifest['objects'] as $object) {
            if (! $this->verifyFile(PrivatePaths::within($disk->path($directory), $object['file']), (string) $object['checksum']) || filesize(PrivatePaths::within($disk->path($directory), $object['file'])) !== $object['bytes']) {
                throw new OperationFailedException('integrity_failed');
            }
        }
    }

    private function verifyFile(string $path, string $expected): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            if (! $this->cipher->verifySnapshotChecksum($handle, $expected)) {
                return false;
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    public function fail(BackupRun $run, string $code): void
    {
        try {
            $this->storage->delete((string) $run->id);
        } catch (Throwable) {
            $code = 'backup_cleanup_failed';
        }
        $run->forceFill([
            'state' => BackupRun::STATE_FAILED,
            'error_code' => $code,
            'failed_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->audit->record('backup.failed', $run->requested_by_user_id, 'failed', [
            'sanitized_context' => ['backup_run_id' => (string) $run->id, 'error_code' => $code],
        ]);
    }

    /**
     * @return list<string>
     */
    private function referencedKekKeyIds(): array
    {
        $ids = DB::table('vault_key_wrappings')->distinct()->orderBy('recipient_key_id')->pluck('recipient_key_id')
            ->merge(DB::table('import_runs')->whereNotNull('random_storage_path')->pluck('import_key_key_id'))
            ->push($this->kek->activeKeyId())
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();

        return array_values($ids->all());
    }

    private function objectBytes(): int
    {
        $bytes = 0;
        foreach ($this->inventory() as $object) {
            $path = PrivatePaths::within(Storage::disk('attachments')->path(''), $object['source_path']);
            $bytes += is_file($path) ? (int) filesize($path) : 0;
        }

        return $bytes;
    }

    private function schemaVersion(): string
    {
        $migrations = DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
        $names = array_map(fn (mixed $name): string => (string) $name, $migrations);

        return count($names).'-'.substr(hash('sha256', implode("\n", $names)), 0, 16);
    }
}
