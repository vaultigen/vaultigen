<?php

namespace App\Services\Operations;

use App\Contracts\KekProvider;
use App\Models\BackupRun;
use App\Models\KeyRotationRun;
use App\Models\User;
use App\Models\Vault;
use App\Services\AuditWriter;
use App\Services\Crypto\AttachmentCipher;
use App\Services\Crypto\EntryCipher;
use App\Services\Crypto\VaultKeyManager;
use App\Services\StrongAuthentication;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

/**
 * Installation-key rewrapping and vault-key rotation.
 *
 * An installation rewrap keeps every vault key unchanged and only rewraps it
 * under a new installation KEK, so no entry data is touched. A vault rotation
 * creates a new vault key version, makes it active for new writes, and then
 * re-encrypts entries, revisions, and attachment file keys in restartable
 * batches. Compare-and-swap conditions mean a concurrent edit is never
 * overwritten; a failed or interrupted run can simply be resumed.
 *
 * The UI always requires fresh strong reauthentication before starting. The
 * console commands act as the trusted host operator and skip the browser step.
 */
final class KeyRotationService
{
    private const BATCH = 200;

    public function __construct(
        private readonly MaintenanceWindow $maintenance,
        private readonly KekProvider $kek,
        private readonly VaultKeyManager $keys,
        private readonly EntryCipher $entries,
        private readonly AttachmentCipher $attachments,
        private readonly AuditWriter $audit,
    ) {}

    /**
     * @throws OperationFailedException
     */
    public function startInstallationRewrap(?User $actor, #[\SensitiveParameter] ?string $password = null, #[\SensitiveParameter] ?string $code = null): KeyRotationRun
    {
        $this->authorize($actor, $password, $code);
        $this->assertRotatable();

        $target = $this->kek->activeKeyId();
        $source = $this->oldestSourceKeyId($target);
        if ($source === null) {
            throw new OperationFailedException('nothing_to_rotate');
        }

        return KeyRotationRun::query()->create([
            'operation' => KeyRotationRun::OPERATION_KEK_REWRAP,
            'vault_id' => null,
            'source_key_id' => $source,
            'target_key_id' => $target,
            'status' => KeyRotationRun::STATUS_PENDING,
            'started_by_user_id' => $actor?->id,
        ]);
    }

    /**
     * @throws OperationFailedException
     */
    public function startVaultRotation(?User $actor, string $vaultId, #[\SensitiveParameter] ?string $password = null, #[\SensitiveParameter] ?string $code = null): KeyRotationRun
    {
        $this->authorize($actor, $password, $code);
        $this->assertRotatable();

        $vault = Vault::query()->find($vaultId);
        if ($vault === null) {
            throw new OperationFailedException('vault_not_found');
        }

        $source = $this->keys->activeVersionId($vaultId);
        $createdBy = $actor === null ? (string) $vault->primary_custodian_user_id : (string) $actor->id;
        $target = $this->keys->rotateActiveVersion($vaultId, $createdBy);

        return KeyRotationRun::query()->create([
            'operation' => KeyRotationRun::OPERATION_VAULT_ROTATE,
            'vault_id' => $vaultId,
            'source_key_id' => $source,
            'target_key_id' => $target,
            'status' => KeyRotationRun::STATUS_PENDING,
            'started_by_user_id' => $actor?->id,
        ]);
    }

    /**
     * Run a rotation to completion, or resume one that failed or was interrupted.
     */
    public function run(KeyRotationRun $run): void
    {
        $run->refresh();
        if ($run->status === KeyRotationRun::STATUS_COMPLETED) {
            return;
        }

        $run->forceFill([
            'status' => KeyRotationRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
            'error_code' => null,
            'failed_at' => null,
            'updated_at' => now(),
        ])->save();

        try {
            match ($run->operation) {
                KeyRotationRun::OPERATION_KEK_REWRAP => $this->runInstallationRewrap($run),
                KeyRotationRun::OPERATION_VAULT_ROTATE => $this->runVaultRotation($run),
                default => throw new OperationFailedException('unknown_operation'),
            };
        } catch (Throwable $exception) {
            $code = $exception instanceof OperationFailedException ? $exception->failureCode : 'rotation_failed';
            $run->forceFill([
                'status' => KeyRotationRun::STATUS_FAILED,
                'error_code' => $code,
                'failed_at' => now(),
                'updated_at' => now(),
            ])->save();
        }
    }

    private function runInstallationRewrap(KeyRotationRun $run): void
    {
        $source = (string) $run->source_key_id;
        $target = (string) $run->target_key_id;
        $cursor = $run->cursor;
        $processed = $run->processed_count;

        while (true) {
            $wrappings = DB::table('vault_key_wrappings')
                ->join('vault_key_versions', 'vault_key_versions.id', '=', 'vault_key_wrappings.vault_key_version_id')
                ->where('vault_key_wrappings.recipient_type', 'server_kek')
                ->where('vault_key_wrappings.recipient_key_id', $source)
                ->whereNull('vault_key_wrappings.retired_at')
                ->when($cursor !== null && $cursor !== '', fn ($query) => $query->where('vault_key_wrappings.id', '>', $cursor))
                ->orderBy('vault_key_wrappings.id')
                ->limit(self::BATCH)
                ->get(['vault_key_wrappings.id', 'vault_key_wrappings.vault_key_version_id', 'vault_key_versions.vault_id']);

            if ($wrappings->isEmpty()) {
                break;
            }

            foreach ($wrappings as $wrapping) {
                $this->keys->rewrapTo((string) $wrapping->vault_id, (string) $wrapping->vault_key_version_id, $source, $target);
                $cursor = (string) $wrapping->id;
                $processed++;
            }

            $run->forceFill(['cursor' => $cursor, 'processed_count' => $processed, 'updated_at' => now()])->save();
        }

        $this->verifyInstallationRewrap($source, $target);

        $run->forceFill([
            'status' => KeyRotationRun::STATUS_COMPLETED,
            'cursor' => null,
            'processed_count' => $processed,
            'completed_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->audit->record('key.installation_rotated', $run->started_by_user_id, 'success', [
            'sanitized_context' => ['rotation_run_id' => (string) $run->id, 'kept_versions' => $processed],
        ]);
    }

    private function verifyInstallationRewrap(string $source, string $target): void
    {
        $remaining = DB::table('vault_key_wrappings')
            ->where('recipient_type', 'server_kek')->where('recipient_key_id', $source)->count();
        if ($remaining > 0) {
            throw new OperationFailedException('references_remain');
        }

        $versions = DB::table('vault_key_wrappings')
            ->join('vault_key_versions', 'vault_key_versions.id', '=', 'vault_key_wrappings.vault_key_version_id')
            ->where('vault_key_wrappings.recipient_type', 'server_kek')
            ->where('vault_key_wrappings.recipient_key_id', $target)
            ->get(['vault_key_wrappings.vault_key_version_id', 'vault_key_versions.vault_id']);

        foreach ($versions as $version) {
            // Throws if any rewrapped key cannot be unwrapped under its recorded KEK.
            $this->keys->unwrap((string) $version->vault_id, (string) $version->vault_key_version_id);
        }
    }

    private function runVaultRotation(KeyRotationRun $run): void
    {
        $vaultId = (string) $run->vault_id;
        $source = (string) $run->source_key_id;
        $target = (string) $run->target_key_id;
        $processed = $run->processed_count;
        $failed = $run->failed_count;

        [$entries, $entryFailures] = $this->reencryptEntries($vaultId, $source, $target);
        [$revisions] = $this->reencryptRevisions($vaultId, $source, $target);
        [$attachments] = $this->rewrapAttachments($vaultId, $source, $target);

        $processed += $entries + $revisions + $attachments;
        $failed += $entryFailures;
        $run->forceFill(['processed_count' => $processed, 'failed_count' => $failed, 'updated_at' => now()])->save();

        $this->assertBackupAllowsRetirement();

        if ($failed > 0 || $this->liveReferenceCount($vaultId, $source) > 0) {
            throw new OperationFailedException('references_remain');
        }

        $this->keys->retireVersion($vaultId, $source);

        $run->forceFill([
            'status' => KeyRotationRun::STATUS_COMPLETED,
            'processed_count' => $processed,
            'failed_count' => $failed,
            'completed_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->audit->record('key.vault_rotated', $run->started_by_user_id, 'success', [
            'vault_id' => $vaultId,
            'target_type' => 'vault',
            'target_id' => $vaultId,
            'sanitized_context' => ['rotation_run_id' => (string) $run->id],
        ]);
    }

    /**
     * @return array{0: int, 1: int} Processed and failed counts.
     */
    private function reencryptEntries(string $vaultId, string $source, string $target): array
    {
        $processed = 0;
        $failed = 0;
        $lastId = '';
        $newVersionNumber = $this->keys->versionNumber($vaultId, $target);

        while (true) {
            $entries = DB::table('entries')
                ->where('vault_id', $vaultId)
                ->where('vault_key_version_id', $source)
                ->when($lastId !== '', fn (Builder $query): Builder => $query->where('id', '>', $lastId))
                ->orderBy('id')
                ->limit(self::BATCH)
                ->get(['id', 'lock_version', 'encrypted_payload']);

            if ($entries->isEmpty()) {
                break;
            }

            foreach ($entries as $entry) {
                $lastId = (string) $entry->id;
                try {
                    $plain = $this->entries->openPayload($vaultId, $lastId, $source, (string) $entry->encrypted_payload);
                    $sealed = $this->entries->sealPayload($vaultId, $lastId, $target, $plain);
                } catch (Throwable) {
                    $failed++;

                    continue;
                }

                $updated = DB::table('entries')
                    ->where('id', $lastId)
                    ->where('vault_key_version_id', $source)
                    ->where('lock_version', (int) $entry->lock_version)
                    ->update([
                        'vault_key_version_id' => $target,
                        'payload_nonce' => $sealed->nonce,
                        'encrypted_payload' => $sealed->envelope,
                        'updated_at' => now(),
                    ]);

                if ($updated === 1) {
                    $processed++;
                }
                // A zero-row update means a concurrent writer already moved the
                // row to the new version, so there is nothing to overwrite.
            }
        }

        return [$processed, $failed];
    }

    /**
     * @return array{0: int} Processed count.
     */
    private function reencryptRevisions(string $vaultId, string $source, string $target): array
    {
        $processed = 0;
        $lastId = '';

        while (true) {
            $revisions = DB::table('entry_revisions')
                ->where('vault_id', $vaultId)
                ->where('vault_key_version_id', $source)
                ->when($lastId !== '', fn (Builder $query): Builder => $query->where('id', '>', $lastId))
                ->orderBy('id')
                ->limit(self::BATCH)
                ->get(['id', 'encrypted_snapshot']);

            if ($revisions->isEmpty()) {
                break;
            }

            foreach ($revisions as $revision) {
                $lastId = (string) $revision->id;
                $plain = $this->entries->openSnapshot($vaultId, $lastId, $source, (string) $revision->encrypted_snapshot);
                $sealed = $this->entries->sealSnapshot($vaultId, $lastId, $target, $plain);
                DB::table('entry_revisions')
                    ->where('id', $lastId)
                    ->where('vault_key_version_id', $source)
                    ->update([
                        'vault_key_version_id' => $target,
                        'payload_nonce' => $sealed->nonce,
                        'encrypted_snapshot' => $sealed->envelope,
                    ]);
                $processed++;
            }
        }

        return [$processed];
    }

    /**
     * @return array{0: int} Processed count.
     */
    private function rewrapAttachments(string $vaultId, string $source, string $target): array
    {
        $processed = 0;

        $attachments = DB::table('file_attachments')
            ->where('vault_id', $vaultId)
            ->where('vault_key_version_id', $source)
            ->orderBy('id')
            ->get(['id', 'wrapped_file_key', 'encrypted_metadata']);

        foreach ($attachments as $attachment) {
            $id = (string) $attachment->id;
            $fileKey = $this->attachments->unwrapFileKey($vaultId, $id, $source, (string) $attachment->wrapped_file_key);
            $wrapped = $this->attachments->wrapFileKey($vaultId, $id, $target, $fileKey);
            $metadata = $this->attachments->openMetadata($vaultId, $id, $source, (string) $attachment->encrypted_metadata);
            $sealedMetadata = $this->attachments->sealMetadata($vaultId, $id, $target, $metadata);

            DB::table('file_attachments')
                ->where('id', $id)
                ->where('vault_key_version_id', $source)
                ->update([
                    'vault_key_version_id' => $target,
                    'wrapped_file_key_nonce' => $wrapped->nonce,
                    'wrapped_file_key' => $wrapped->envelope,
                    'encrypted_metadata' => $sealedMetadata->envelope,
                    'updated_at' => now(),
                ]);
            $processed++;
        }

        return [$processed];
    }

    private function liveReferenceCount(string $vaultId, string $source): int
    {
        return DB::table('entries')->where('vault_id', $vaultId)->where('vault_key_version_id', $source)->count()
            + DB::table('entry_revisions')->where('vault_id', $vaultId)->where('vault_key_version_id', $source)->count()
            + DB::table('file_attachments')->where('vault_id', $vaultId)->where('vault_key_version_id', $source)->count();
    }

    /**
     * @throws OperationFailedException
     */
    private function assertBackupAllowsRetirement(): void
    {
        if (! BackupRun::query()->where('state', BackupRun::STATE_COMPLETED)->exists()) {
            throw new OperationFailedException('backup_required');
        }
    }

    private function oldestSourceKeyId(string $target): ?string
    {
        $ids = DB::table('vault_key_wrappings')
            ->where('recipient_type', 'server_kek')
            ->whereNull('retired_at')
            ->distinct()
            ->orderBy('recipient_key_id')
            ->pluck('recipient_key_id');

        foreach ($ids as $id) {
            if (is_string($id) && $id !== '' && $id !== $target) {
                return $id;
            }
        }

        return null;
    }

    private function assertRotatable(): void
    {
        if ($this->maintenance->active()) {
            throw new OperationFailedException('maintenance_active');
        }
        if (KeyRotationRun::query()->whereIn('status', [KeyRotationRun::STATUS_PENDING, KeyRotationRun::STATUS_RUNNING])->exists()) {
            throw new OperationFailedException('rotation_in_progress');
        }
        if (BackupRun::query()->whereIn('state', [BackupRun::STATE_PENDING, BackupRun::STATE_RUNNING])->exists()) {
            throw new OperationFailedException('backup_in_progress');
        }
    }

    private function authorize(?User $actor, #[\SensitiveParameter] ?string $password, #[\SensitiveParameter] ?string $code): void
    {
        if ($actor === null) {
            return;
        }

        Gate::forUser($actor)->authorize('manage-installation');
        if ($password === null || $code === null) {
            throw new RuntimeException('Strong reauthentication is required.');
        }
        app(StrongAuthentication::class)->confirm($actor, $password, $code);
    }
}
