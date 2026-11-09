<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\Folder;
use App\Models\KeyRotationRun;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * The trash lifecycle for entries and folders, plus the retention-bound purge
 * that a scheduled command runs.
 *
 * Deleting moves a row to trash; it never removes ciphertext immediately. A
 * folder can only be deleted once it holds no live entries, and it is never
 * cascaded. Restoring a trashed entry reattaches it to its former folder when
 * that folder is still live, otherwise it falls back to the vault root
 * ("No folder"). Purging is idempotent and records a sanitized failed event so
 * the Activity page shows retry status. A vault whose purge waiting period has
 * ended is deleted here too, with everything it contains.
 */
class TrashLifecycle
{
    public function __construct(
        private readonly AuditWriter $audit,
        private readonly VaultAccess $access,
        private readonly InstallationSettings $settings,
        private readonly AttachmentQuota $quota,
    ) {}

    public function trashEntry(User $actor, string $entryId): void
    {
        $entry = $this->findEntry($entryId, trashed: false);
        if (! $this->access->allowsOnEntry($actor, $entry, 'entry.delete')) {
            throw new AuthorizationException((string) __('entries.errors.delete_not_allowed'));
        }
        $this->activeVault((string) $entry->vault_id);

        $this->audit->transaction(function () use ($actor, $entry): void {
            DB::table('entries')->where('id', $entry->id)->whereNull('deleted_at')->update([
                'deleted_at' => now(),
                'deleted_by_user_id' => $actor->id,
                'purge_after' => now()->addDays($this->settings->trashRetentionDays()),
                'updated_at' => now(),
            ]);
            $this->audit->record('entry.deleted', $actor->id);
        });
    }

    public function restoreEntry(User $actor, string $entryId): void
    {
        $entry = $this->findEntry($entryId, trashed: true);
        if (! $this->access->allowsOnEntry($actor, $entry, 'entry.restore')) {
            throw new AuthorizationException((string) __('entries.errors.trash_restore_not_allowed'));
        }
        $this->activeVault((string) $entry->vault_id);

        $this->audit->transaction(function () use ($actor, $entry): void {
            DB::table('entries')->where('id', $entry->id)->whereNotNull('deleted_at')->update([
                'folder_id' => $this->restorableFolderId((string) $entry->vault_id, $entry->folder_id),
                'deleted_at' => null,
                'deleted_by_user_id' => null,
                'purge_after' => null,
                'updated_at' => now(),
            ]);
            $this->audit->record('entry.restored', $actor->id);
        });
    }

    /**
     * @return Builder<Entry>
     */
    public function trashedEntries(User $user, string $vaultId): Builder
    {
        abort_unless($this->access->allows($user, $vaultId, 'entry.delete'), 404);

        return Entry::query()
            ->where('entries.vault_id', $vaultId)
            ->whereNotNull('entries.deleted_at')
            ->with(['folder', 'owner']);
    }

    public function trashFolder(User $actor, string $folderId): void
    {
        $folder = $this->findFolder($folderId, trashed: false);
        if (! $this->access->allows($actor, (string) $folder->vault_id, 'folder.delete')) {
            throw new AuthorizationException((string) __('folders.errors.manager_only'));
        }
        $this->activeVault((string) $folder->vault_id);

        if ($this->liveEntryCount((string) $folder->id) > 0) {
            throw ValidationException::withMessages(['folder' => __('folders.errors.not_empty')]);
        }

        $this->audit->transaction(function () use ($actor, $folder): void {
            DB::table('folders')->where('id', $folder->id)->whereNull('deleted_at')->update([
                'deleted_at' => now(),
                'deleted_by_user_id' => $actor->id,
                'purge_after' => now()->addDays($this->settings->trashRetentionDays()),
                'updated_at' => now(),
            ]);
            $this->audit->record('folder.deleted', $actor->id);
        });
    }

    public function restoreFolder(User $actor, string $folderId): void
    {
        $folder = $this->findFolder($folderId, trashed: true);
        if (! $this->access->allows($actor, (string) $folder->vault_id, 'folder.delete')) {
            throw new AuthorizationException((string) __('folders.errors.manager_only'));
        }
        $this->activeVault((string) $folder->vault_id);

        $this->audit->transaction(function () use ($actor, $folder): void {
            DB::table('folders')->where('id', $folder->id)->whereNotNull('deleted_at')->update([
                'deleted_at' => null,
                'deleted_by_user_id' => null,
                'purge_after' => null,
                'updated_at' => now(),
            ]);
            $this->audit->record('folder.restored', $actor->id);
        });
    }

    /**
     * @return Builder<Folder>
     */
    public function trashedFolders(User $user, string $vaultId): Builder
    {
        abort_unless($this->access->allows($user, $vaultId, 'folder.delete'), 404);

        return Folder::query()
            ->where('vault_id', $vaultId)
            ->whereNotNull('deleted_at');
    }

    /**
     * Purge every eligible trashed row and prune retained revisions. Each item
     * is independent: one failure does not stop the rest, and a failed item
     * stays eligible for the next run.
     *
     * @return array{vaults: int, entries: int, folders: int, revisions: int, attachments: int, reservations: int, failures: int}
     */
    public function purgeDue(int $limit = 100): array
    {
        $result = ['vaults' => 0, 'entries' => 0, 'folders' => 0, 'revisions' => 0, 'attachments' => 0, 'reservations' => 0, 'failures' => 0];

        foreach ($this->dueVaultIds($limit) as $vaultId) {
            try {
                $this->purgeVault($vaultId);
                $result['vaults']++;
            } catch (Throwable) {
                $result['failures']++;
                $this->audit->record('vault.purged', null, 'failed', ['vault_id' => $vaultId, 'target_type' => 'vault', 'target_id' => $vaultId]);
            }
        }

        foreach ($this->dueEntryIds($limit) as $entryId) {
            try {
                $this->purgeEntry((string) $entryId);
                $result['entries']++;
            } catch (Throwable) {
                $result['failures']++;
                $this->audit->record('entry.purged', null, 'failed');
            }
        }

        foreach ($this->dueFolderIds($limit) as $folderId) {
            try {
                $this->purgeFolder((string) $folderId);
                $result['folders']++;
            } catch (Throwable) {
                $result['failures']++;
                $this->audit->record('folder.purged', null, 'failed');
            }
        }

        $attachments = $this->purgeDueAttachments($limit);
        $result['attachments'] = $attachments['purged'];
        $result['failures'] += $attachments['failures'];
        $result['revisions'] = $this->pruneRevisions($limit);
        $result['reservations'] = $this->quota->releaseExpired();

        return $result;
    }

    /**
     * Permanently delete a vault whose waiting period has ended. The audit log
     * keeps the vault's id and name as the record of what was removed.
     */
    public function purgeVault(string $vaultId): void
    {
        $this->audit->transaction(function () use ($vaultId): void {
            $vault = DB::table('vaults')
                ->where('id', $vaultId)
                ->where('state', 'pending_purge')
                ->where('purge_after', '<=', now())
                ->first();
            if ($vault === null) {
                return;
            }

            $entryIds = DB::table('entries')->where('vault_id', $vaultId)->select('id');
            $folderIds = DB::table('folders')->where('vault_id', $vaultId)->select('id');
            $keyVersionIds = DB::table('vault_key_versions')->where('vault_id', $vaultId)->select('id');

            foreach (DB::table('file_attachments')->where('vault_id', $vaultId)->pluck('random_storage_path') as $path) {
                $this->deleteObject((string) $path);
            }

            DB::table('secret_reveals')->where('vault_id', $vaultId)->delete();
            DB::table('entry_tag')->where('vault_id', $vaultId)->delete();
            DB::table('entry_grants')->whereIn('entry_id', $entryIds)->delete();
            DB::table('entry_revisions')->where('vault_id', $vaultId)->delete();
            DB::table('file_attachments')->where('vault_id', $vaultId)->delete();
            DB::table('attachment_reservations')->where('vault_id', $vaultId)->delete();
            DB::table('entries')->where('vault_id', $vaultId)->delete();
            DB::table('folder_grants')->whereIn('folder_id', $folderIds)->delete();
            DB::table('folders')->where('vault_id', $vaultId)->delete();
            DB::table('tags')->where('vault_id', $vaultId)->delete();
            DB::table('vault_grants')->where('vault_id', $vaultId)->delete();
            DB::table('emergency_access_sessions')->where('vault_id', $vaultId)->delete();
            DB::table('vault_active_keys')->where('vault_id', $vaultId)->delete();
            DB::table('vault_key_wrappings')->whereIn('vault_key_version_id', $keyVersionIds)->delete();
            DB::table('vault_key_versions')->where('vault_id', $vaultId)->delete();
            DB::table('vaults')->where('id', $vaultId)->delete();

            $this->audit->record('vault.purged', null, 'success', [
                'vault_id' => $vaultId,
                'target_type' => 'vault',
                'target_id' => $vaultId,
                'sanitized_context' => ['name' => (string) $vault->name],
            ]);
        });
    }

    /**
     * Purge every attachment whose retention window has elapsed, removing the
     * encrypted object before the database row. Failures keep the row eligible
     * and write a sanitized event.
     *
     * @return array{purged: int, failures: int}
     */
    public function purgeDueAttachments(int $limit = 100): array
    {
        $rows = DB::table('file_attachments')
            ->whereNotNull('deleted_at')
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->orderBy('purge_after')
            ->limit($limit)
            ->get(['id']);

        $purged = 0;
        $failures = 0;
        foreach ($rows as $row) {
            try {
                $this->purgeAttachment((string) $row->id);
                $purged++;
            } catch (Throwable) {
                $failures++;
                $this->audit->record('attachment.deleted', null, 'failed');
            }
        }

        return ['purged' => $purged, 'failures' => $failures];
    }

    public function purgeAttachment(string $attachmentId): void
    {
        $this->audit->transaction(function () use ($attachmentId): void {
            $row = DB::table('file_attachments')
                ->where('id', $attachmentId)
                ->whereNotNull('deleted_at')
                ->where('purge_after', '<=', now())
                ->first();
            if ($row === null) {
                return;
            }

            $this->deleteObject((string) $row->random_storage_path);
            DB::table('file_attachments')->where('id', $attachmentId)->delete();
            $this->audit->record('attachment.deleted');
        });
    }

    public function purgeEntry(string $entryId): void
    {
        $this->audit->transaction(function () use ($entryId): void {
            $exists = DB::table('entries')
                ->where('id', $entryId)
                ->whereNotNull('deleted_at')
                ->where('purge_after', '<=', now())
                ->exists();
            if (! $exists) {
                return;
            }

            DB::table('secret_reveals')->where('entry_id', $entryId)->delete();
            DB::table('entry_tag')->where('entry_id', $entryId)->delete();
            DB::table('entry_grants')->where('entry_id', $entryId)->delete();
            DB::table('entry_revisions')->where('entry_id', $entryId)->delete();
            $this->purgeEntryAttachments($entryId);
            DB::table('entries')->where('id', $entryId)->whereNotNull('deleted_at')->delete();
            $this->audit->record('entry.purged');
        });
    }

    private function purgeEntryAttachments(string $entryId): void
    {
        $paths = DB::table('file_attachments')->where('entry_id', $entryId)->pluck('random_storage_path');
        foreach ($paths as $path) {
            $this->deleteObject((string) $path);
        }
        DB::table('file_attachments')->where('entry_id', $entryId)->delete();
    }

    private function deleteObject(string $path): void
    {
        Storage::disk(EntryAttachments::DISK)->delete($path);
    }

    public function purgeFolder(string $folderId): void
    {
        $this->audit->transaction(function () use ($folderId): void {
            $exists = DB::table('folders')
                ->where('id', $folderId)
                ->whereNotNull('deleted_at')
                ->where('purge_after', '<=', now())
                ->exists();
            if (! $exists) {
                return;
            }

            // A folder is never purged while any entry, including a trashed one,
            // still references it. The entry purge runs first in the same pass.
            if ($this->entryReferenceCount($folderId) > 0) {
                throw new RuntimeException('The folder is still referenced by entries.');
            }

            DB::table('folder_grants')->where('folder_id', $folderId)->delete();
            DB::table('folders')->where('id', $folderId)->whereNotNull('deleted_at')->delete();
            $this->audit->record('folder.purged');
        });
    }

    public function pruneRevisions(int $limit = 100): int
    {
        $settings = $this->settings->values();
        $count = $settings['revision_retention_count'];
        $days = $settings['revision_retention_days'];

        if ($count === 0 && $days === 0) {
            return 0;
        }

        $cutoff = $days > 0 ? now()->subDays($days) : null;
        $pruned = 0;

        $entryIds = DB::table('entry_revisions')
            ->distinct()
            ->orderBy('entry_id')
            ->limit($limit)
            ->pluck('entry_id');

        foreach ($entryIds as $entryId) {
            $entryId = (string) $entryId;
            $revisions = DB::table('entry_revisions')
                ->where('entry_id', $entryId)
                ->orderByDesc('revision_number')
                ->get(['id', 'revision_number', 'created_at']);

            $remove = [];
            foreach ($revisions as $index => $revision) {
                $beyondCount = $count > 0 && $index >= $count;
                $beyondAge = $cutoff !== null
                    && $revision->created_at !== null
                    && Carbon::parse((string) $revision->created_at)->lt($cutoff);
                if ($beyondCount || $beyondAge) {
                    $remove[] = (string) $revision->id;
                }
            }

            if ($remove === []) {
                continue;
            }

            $this->audit->transaction(function () use ($remove, $entryId, &$pruned): void {
                $deleted = DB::table('entry_revisions')->where('entry_id', $entryId)->whereIn('id', $remove)->delete();
                if ($deleted > 0) {
                    $pruned += $deleted;
                    $this->audit->record('entry.revisions_pruned');
                }
            });
        }

        return $pruned;
    }

    /**
     * Vaults past their waiting period. One with a key rotation in flight waits
     * for the next run.
     *
     * @return list<string>
     */
    private function dueVaultIds(int $limit): array
    {
        return array_values(DB::table('vaults')
            ->where('state', 'pending_purge')
            ->where('purge_after', '<=', now())
            ->whereNotIn('id', DB::table('key_rotation_runs')
                ->whereNotNull('vault_id')
                ->whereIn('status', [KeyRotationRun::STATUS_PENDING, KeyRotationRun::STATUS_RUNNING])
                ->select('vault_id'))
            ->orderBy('purge_after')
            ->limit($limit)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    /**
     * @return list<string>
     */
    private function dueEntryIds(int $limit): array
    {
        return array_values(DB::table('entries')
            ->whereNotNull('deleted_at')
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->orderBy('purge_after')
            ->limit($limit)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    /**
     * @return list<string>
     */
    private function dueFolderIds(int $limit): array
    {
        return array_values(DB::table('folders')
            ->whereNotNull('deleted_at')
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->whereNotExists(fn ($query) => $query->select(DB::raw('1'))->from('entries')->whereColumn('entries.folder_id', 'folders.id'))
            ->orderBy('purge_after')
            ->limit($limit)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    private function findEntry(string $entryId, bool $trashed): Entry
    {
        $entry = Entry::query()->whereKey($entryId)->first();
        $matches = $trashed ? $entry?->deleted_at !== null : $entry !== null && $entry->deleted_at === null;
        abort_if($entry === null || ! $matches, 404);

        return $entry;
    }

    private function findFolder(string $folderId, bool $trashed): Folder
    {
        $folder = Folder::query()->whereKey($folderId)->first();
        $matches = $trashed ? $folder?->deleted_at !== null : $folder !== null && $folder->deleted_at === null;
        abort_if($folder === null || ! $matches, 404);

        return $folder;
    }

    private function restorableFolderId(string $vaultId, ?string $folderId): ?string
    {
        if ($folderId === null) {
            return null;
        }

        return DB::table('folders')
            ->where('id', $folderId)
            ->where('vault_id', $vaultId)
            ->whereNull('deleted_at')
            ->exists() ? $folderId : null;
    }

    private function activeVault(string $vaultId): Vault
    {
        $vault = Vault::query()->findOrFail($vaultId);
        if ($vault->state !== 'active') {
            throw ValidationException::withMessages(['vault' => __('entries.validation.vault_not_active')]);
        }

        return $vault;
    }

    private function liveEntryCount(string $folderId): int
    {
        return DB::table('entries')->where('folder_id', $folderId)->whereNull('deleted_at')->count();
    }

    private function entryReferenceCount(string $folderId): int
    {
        return DB::table('entries')->where('folder_id', $folderId)->count();
    }
}
