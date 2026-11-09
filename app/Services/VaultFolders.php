<?php

namespace App\Services;

use App\Models\Folder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Authorized, Manager-only folder administration for one flat folder level.
 *
 * Folder names are normalized and unique per vault; rename uses optimistic
 * locking; every write is audited. Nested folders are rejected at the database
 * boundary (parent_id null, depth 0), so there is no nesting input here.
 */
final class VaultFolders
{
    public const MAX_NAME = 100;

    public function __construct(
        private readonly AuditWriter $audit,
        private readonly VaultAccess $access,
        private readonly Normalizer $normalizer,
    ) {}

    public function create(User $actor, string $vaultId, string $name): string
    {
        $this->assertManager($actor, $vaultId);
        $this->assertVaultActive($vaultId);
        $normalized = $this->validateName($name);
        $this->assertUnique($vaultId, $normalized);

        $folderId = (string) Str::uuid();
        $this->audit->transaction(function () use ($actor, $vaultId, $name, $normalized, $folderId): void {
            DB::table('folders')->insert([
                'id' => $folderId,
                'vault_id' => $vaultId,
                'parent_id' => null,
                'name' => trim($name),
                'normalized_name' => $normalized,
                'depth' => 0,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('folder.created', $actor->id, 'success', ['vault_id' => $vaultId, 'target_type' => 'folder', 'target_id' => $folderId]);
        });

        return $folderId;
    }

    public function rename(User $actor, string $folderId, string $name, int $expectedLockVersion): void
    {
        $folder = $this->folderInVault($folderId);
        $this->assertManager($actor, (string) $folder->vault_id);
        $this->assertVaultActive((string) $folder->vault_id);
        $normalized = $this->validateName($name);
        $this->assertUnique((string) $folder->vault_id, $normalized, $folderId);

        if ($folder->lock_version !== $expectedLockVersion) {
            throw ValidationException::withMessages(['name' => __('folders.errors.stale')]);
        }

        $this->audit->transaction(function () use ($actor, $folder, $name, $normalized, $expectedLockVersion): void {
            DB::table('folders')->where('id', $folder->id)->where('vault_id', $folder->vault_id)->update([
                'name' => trim($name),
                'normalized_name' => $normalized,
                'lock_version' => $expectedLockVersion + 1,
                'updated_at' => now(),
            ]);
            $this->audit->record('folder.updated', $actor->id, 'success', ['vault_id' => (string) $folder->vault_id, 'target_type' => 'folder', 'target_id' => (string) $folder->id]);
        });
    }

    /**
     * Resolve a folder the user may browse, as a 404 otherwise.
     */
    public function resolve(User $user, string $folderId): Folder
    {
        $folder = Folder::query()->find($folderId);
        abort_if($folder === null || $folder->deleted_at !== null, 404);
        abort_unless($this->access->allowsOnFolder($user, $folderId, 'metadata.view'), 404);

        return $folder;
    }

    /**
     * The user's authorized folders in a vault as an id => name option map.
     *
     * @return array<string, string>
     */
    public function options(User $user, string $vaultId): array
    {
        return $this->access->visibleFolders($user, $vaultId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function folderInVault(string $folderId): Folder
    {
        $folder = Folder::query()->find($folderId);
        abort_if($folder === null || $folder->deleted_at !== null, 404);

        return $folder;
    }

    private function assertManager(User $actor, string $vaultId): void
    {
        if (! $this->access->allows($actor, $vaultId, 'folder.create')) {
            throw new AuthorizationException((string) __('folders.errors.manager_only'));
        }
    }

    private function assertVaultActive(string $vaultId): void
    {
        if (DB::table('vaults')->where('id', $vaultId)->value('state') !== 'active') {
            throw ValidationException::withMessages(['name' => __('folders.errors.vault_inactive')]);
        }
    }

    private function validateName(string $name): string
    {
        $normalized = $this->normalizer->normalize($name);
        if ($normalized === '') {
            throw ValidationException::withMessages(['name' => __('folders.errors.name_required')]);
        }
        Validator::make(['name' => trim($name)], ['name' => ['required', 'string', 'max:'.self::MAX_NAME]])->validate();

        return $normalized;
    }

    private function assertUnique(string $vaultId, string $normalized, ?string $exceptId = null): void
    {
        $query = DB::table('folders')->where('vault_id', $vaultId)->where('normalized_name', $normalized);
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => __('folders.errors.duplicate')]);
        }
    }
}
