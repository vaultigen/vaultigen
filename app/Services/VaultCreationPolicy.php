<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Installation-level policy for who may create shared vault shells, plus the
 * per-creator and installation creation quotas that bound mass creation.
 */
final class VaultCreationPolicy
{
    public const POLICIES = ['admins', 'vault_creators', 'any_active_user'];

    public function __construct(private readonly AuditWriter $audit) {}

    public function allows(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return match ($this->policy()) {
            'admins' => $user->isSuperAdmin(),
            'any_active_user' => true,
            default => $user->isSuperAdmin() || $user->isVaultCreator(),
        };
    }

    public function policy(): string
    {
        $value = (string) DB::table('installation_settings')->where('id', 1)->value('vault_creation_policy');

        return in_array($value, self::POLICIES, true) ? $value : 'vault_creators';
    }

    public function perUserLimit(): int
    {
        return (int) DB::table('installation_settings')->where('id', 1)->value('vault_creation_limit_per_user');
    }

    public function installationLimit(): int
    {
        return (int) DB::table('installation_settings')->where('id', 1)->value('vault_creation_limit_installation');
    }

    public function assertWithinQuota(User $actor): void
    {
        $createdByActor = DB::table('vaults')->where('kind', 'shared')->where('created_by_user_id', $actor->id)->count();
        $total = DB::table('vaults')->where('kind', 'shared')->count();

        if ($createdByActor < $this->perUserLimit() && $total < $this->installationLimit()) {
            return;
        }

        $this->audit->record('vault.creation_denied', $actor->id, 'denied');

        throw ValidationException::withMessages([
            'name' => __('vaults.errors.creation_limit_reached'),
        ]);
    }
}
