<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Manager-only vault membership changes.
 *
 * Grantors are limited to their own effective authority: they must hold an
 * active Manager grant, and any expiry they set may not outlive their own.
 */
final class VaultMembership
{
    public const PERMISSIONS = ['viewer', 'editor', 'manager'];

    public function __construct(
        private readonly AuditWriter $audit,
        private readonly VaultAccess $access,
    ) {}

    public function grant(User $actor, string $vaultId, string $principalId, string $permission, ?string $expiresAt = null): void
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
        $this->assertWithinAuthority($authority['expires_at'], $expiresAt);

        $this->audit->transaction(function () use ($actor, $vaultId, $principalId, $permission, $expiresAt): void {
            DB::table('vault_grants')
                ->where('vault_id', $vaultId)
                ->where('principal_id', $principalId)
                ->whereNotNull('active_slot')
                ->update([
                    'active_slot' => null,
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $actor->id,
                    'revocation_reason' => 'replaced',
                    'updated_at' => now(),
                ]);
            DB::table('vault_grants')->insert([
                'id' => (string) Str::uuid(),
                'principal_id' => $principalId,
                'vault_id' => $vaultId,
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
                'target_type' => 'vault',
                'target_id' => $vaultId,
                'sanitized_context' => ['permission' => $permission, 'principal_id' => $principalId],
            ]);
        });
    }

    public function revoke(User $actor, string $vaultId, string $grantId): void
    {
        $this->managerAuthority($actor, $vaultId);
        $this->assertVaultActive($vaultId);

        $this->audit->transaction(function () use ($actor, $vaultId, $grantId): void {
            $grant = DB::table('vault_grants')->where('id', $grantId)->where('vault_id', $vaultId)->first();
            abort_if($grant === null, 404);
            abort_if($grant->active_slot === null, 422, (string) __('vaults.errors.grant_already_inactive'));
            if ($grant->permission === 'manager' && $this->activeManagerCount($vaultId) <= 1) {
                throw ValidationException::withMessages(['grant' => __('vaults.errors.keep_one_manager')]);
            }
            DB::table('vault_grants')->where('id', $grantId)->update([
                'active_slot' => null,
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->id,
                'updated_at' => now(),
            ]);
            $this->audit->record('grant.revoked', $actor->id, 'success', [
                'vault_id' => $vaultId,
                'target_type' => 'vault',
                'target_id' => $vaultId,
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
        abort_if($authority === null || $authority['permission'] !== 'manager', 403, (string) __('vaults.errors.manager_membership_only'));

        return $authority;
    }

    private function assertWithinAuthority(?Carbon $authorityExpiry, ?string $expiresAt): void
    {
        if ($authorityExpiry === null) {
            return;
        }
        if ($expiresAt === null || Carbon::parse($expiresAt)->greaterThan($authorityExpiry)) {
            throw ValidationException::withMessages(['expires_at' => __('vaults.errors.expiry_after_authority')]);
        }
    }

    private function assertVaultActive(string $vaultId): void
    {
        $state = DB::table('vaults')->where('id', $vaultId)->value('state');
        if ($state !== 'active') {
            throw ValidationException::withMessages(['vault' => __('vaults.errors.membership_vault_inactive')]);
        }
    }

    private function activeManagerCount(string $vaultId): int
    {
        return DB::table('vault_grants')
            ->where('vault_id', $vaultId)
            ->where('permission', 'manager')
            ->whereNotNull('active_slot')
            ->whereNull('revoked_at')
            ->where(fn ($expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();
    }
}
