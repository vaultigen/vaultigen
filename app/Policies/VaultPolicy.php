<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vault;
use App\Services\VaultAccess;
use Illuminate\Support\Facades\Gate;

/**
 * Authorization for the vault administration screen.
 *
 * Viewing and editing a vault shell never grants access to its contents;
 * membership changes remain governed by VaultMembership and require an
 * active Manager grant on the selected vault.
 */
class VaultPolicy
{
    public function viewAny(User $user): bool
    {
        $access = app(VaultAccess::class);

        return Gate::forUser($user)->allows('manage-installation')
            || $access->governsAnyVault($user)
            || $access->managesAnyVault($user);
    }

    public function view(User $user, Vault $vault): bool
    {
        $access = app(VaultAccess::class);

        return Gate::forUser($user)->allows('manage-installation')
            || $access->canGovern($user, $vault->id)
            || $access->can($user, $vault->id, 'manager');
    }

    public function create(User $user): bool
    {
        return Gate::forUser($user)->allows('manage-installation');
    }

    public function update(User $user, Vault $vault): bool
    {
        $access = app(VaultAccess::class);

        return Gate::forUser($user)->allows('manage-installation')
            || $access->canGovern($user, $vault->id)
            || $access->can($user, $vault->id, 'manager');
    }
}
