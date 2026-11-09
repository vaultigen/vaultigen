<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Manager-only additive grants on a single folder or entry.
 *
 * Creating and holding access stay separate: only a vault Manager may add an
 * additional grant, grants are additive and can never reduce an inherited vault
 * grant, replaced grants are kept as history, and an expiry may not outlive the
 * grantor's own access.
 */
final class ScopedGrants
{
    public const PERMISSIONS = ['viewer', 'editor', 'manager'];

    public function __construct(
        private readonly AuditWriter $audit,
        private readonly VaultAccess $access,
        private readonly VaultFolders $folders,
        private readonly EntryVault $entries,
    ) {}

    public function grantOnFolder(User $actor, string $folderId, string $principalId, string $permission, ?string $expiresAt = null): void
    {
        $folder = $this->folders->resolve($actor, $folderId);
        $this->grant($actor, (string) $folder->vault_id, 'folder_grants', 'folder_id', $folderId, $principalId, $permission, $expiresAt);
    }

    public function grantOnEntry(User $actor, string $entryId, string $principalId, string $permission, ?string $expiresAt = null): void
    {
        $entry = $this->entries->find($actor, $entryId);
        $this->grant($actor, (string) $entry->vault_id, 'entry_grants', 'entry_id', $entryId, $principalId, $permission, $expiresAt);
    }

    public function revokeFolder(User $actor, string $folderId, string $grantId): void
    {
        $folder = $this->folders->resolve($actor, $folderId);
        $this->revoke($actor, (string) $folder->vault_id, 'folder_grants', 'folder_id', $folderId, $grantId);
    }

    public function revokeEntry(User $actor, string $entryId, string $grantId): void
    {
        $entry = $this->entries->find($actor, $entryId);
        $this->revoke($actor, (string) $entry->vault_id, 'entry_grants', 'entry_id', $entryId, $grantId);
    }

    private function grant(User $actor, string $vaultId, string $table, string $column, string $targetId, string $principalId, string $permission, ?string $expiresAt): void
    {
        $authority = $this->managerAuthority($actor, $vaultId);
        $this->assertVaultActive($vaultId);
        Validator::make(
            ['principal_id' => $principalId, 'permission' => $permission, 'expires_at' => $expiresAt],
            [
                'principal_id' => ['required', 'uuid', 'exists:principals,id'],
                'permission' => ['required', Rule::in(self::PERMISSIONS)],
                'expires_at' => ['nullable', 'date', 'after:now'],
            ],
        )->validate();
        if ($authority['expires_at'] !== null && ($expiresAt === null || Carbon::parse($expiresAt)->greaterThan($authority['expires_at']))) {
            throw ValidationException::withMessages(['expires_at' => __('vaults.errors.expiry_after_authority')]);
        }

        $targetType = $table === 'folder_grants' ? 'folder' : 'entry';

        $this->audit->transaction(function () use ($actor, $vaultId, $table, $column, $targetId, $targetType, $principalId, $permission, $expiresAt): void {
            DB::table($table)
                ->where($column, $targetId)
                ->where('principal_id', $principalId)
                ->whereNotNull('active_slot')
                ->update([
                    'active_slot' => null,
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $actor->id,
                    'revocation_reason' => 'replaced',
                    'updated_at' => now(),
                ]);
            DB::table($table)->insert([
                'id' => (string) Str::uuid(),
                'principal_id' => $principalId,
                $column => $targetId,
                'permission' => $permission,
                'source' => 'manual',
                'granted_by_user_id' => $actor->id,
                'expires_at' => $expiresAt,
                'active_slot' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('grant.created', $actor->id, 'success', [
                'vault_id' => $vaultId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'sanitized_context' => ['permission' => $permission, 'principal_id' => $principalId],
            ]);
        });
    }

    private function revoke(User $actor, string $vaultId, string $table, string $column, string $targetId, string $grantId): void
    {
        $this->managerAuthority($actor, $vaultId);
        $this->assertVaultActive($vaultId);

        $targetType = $table === 'folder_grants' ? 'folder' : 'entry';

        $this->audit->transaction(function () use ($actor, $vaultId, $table, $column, $targetId, $targetType, $grantId): void {
            $grant = DB::table($table)->where('id', $grantId)->where($column, $targetId)->first();
            abort_if($grant === null, 404);
            abort_if($grant->active_slot === null, 422, (string) __('vaults.errors.grant_already_inactive'));
            DB::table($table)->where('id', $grantId)->update([
                'active_slot' => null,
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->id,
                'updated_at' => now(),
            ]);
            $this->audit->record('grant.revoked', $actor->id, 'success', [
                'vault_id' => $vaultId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'sanitized_context' => ['permission' => (string) $grant->permission, 'principal_id' => (string) $grant->principal_id],
            ]);
        });
    }

    /**
     * @return array{permission: string, expires_at: Carbon|null}
     */
    private function managerAuthority(User $actor, string $vaultId): array
    {
        $authority = $this->access->authority($actor, $vaultId);
        if ($authority === null || $authority['permission'] !== 'manager') {
            throw new AuthorizationException((string) __('vaults.errors.manager_additional_grants_only'));
        }

        return $authority;
    }

    private function assertVaultActive(string $vaultId): void
    {
        if (DB::table('vaults')->where('id', $vaultId)->value('state') !== 'active') {
            throw ValidationException::withMessages(['vault' => __('vaults.errors.grants_vault_inactive')]);
        }
    }
}
