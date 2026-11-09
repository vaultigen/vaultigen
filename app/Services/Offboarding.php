<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Offboards a user with an explicit decision for every vault they own or govern.
 *
 * Deactivation immediately invalidates sessions and remember tokens, and the
 * account cannot authenticate. Each owned personal work vault must be converted
 * into a shared vault, archived, or marked for retention-bound purge, and every
 * shared vault where the user is Custodian must name a new Custodian. The report
 * lists credentials the user revealed so they can be rotated, without ever
 * showing a secret value.
 */
final class Offboarding
{
    public const DECISIONS = ['convert', 'archive', 'purge'];

    public function __construct(
        private readonly AuditWriter $audit,
        private readonly SecretReveals $reveals,
    ) {}

    /**
     * @return Collection<int, Vault>
     */
    public function personalVaults(User $target): Collection
    {
        return Vault::query()
            ->where('kind', Vault::KIND_PRIVATE)
            ->where('private_workspace_owner_user_id', $target->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Vault>
     */
    public function custodianVaults(User $target): Collection
    {
        return Vault::query()
            ->where('kind', Vault::KIND_SHARED)
            ->where('primary_custodian_user_id', $target->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<array{entry_id: string, title: string, vault_id: string, vault_name: string, type: string, last_revealed_at: Carbon|null, reveal_count: int}>
     */
    public function report(User $target): array
    {
        return $this->reveals->forUser($target);
    }

    /**
     * @param  array<string, mixed>  $decisions
     */
    public function offboard(User $actor, User $target, array $decisions): void
    {
        Gate::forUser($actor)->authorize('manage-installation');

        if ($actor->id === $target->id) {
            throw ValidationException::withMessages(['user_id' => __('offboarding.errors.cannot_offboard_self')]);
        }

        $personal = $this->personalVaults($target);
        $custodian = $this->custodianVaults($target);
        $personalDecisions = $this->indexDecisions(is_array($decisions['personal'] ?? null) ? $decisions['personal'] : []);
        $custodianDecisions = $this->indexDecisions(is_array($decisions['custodian'] ?? null) ? $decisions['custodian'] : []);

        foreach ($personal as $vault) {
            $item = $personalDecisions[$vault->id] ?? null;
            $decision = is_array($item) ? ($item['decision'] ?? null) : null;
            if (! is_string($decision) || ! in_array($decision, self::DECISIONS, true)) {
                throw ValidationException::withMessages(['personal' => __('offboarding.errors.personal_decision_required', ['vault' => $vault->name])]);
            }
            if ($decision === 'convert') {
                $this->activeRecipient(is_string($item['custodian_user_id'] ?? null) ? $item['custodian_user_id'] : null, $target, 'custodian', $vault->name);
                $this->activeRecipient(is_string($item['manager_user_id'] ?? null) ? $item['manager_user_id'] : null, $target, 'manager', $vault->name);
            }
        }

        foreach ($custodian as $vault) {
            $userId = $custodianDecisions[$vault->id]['transfer_user_id'] ?? null;
            $this->activeRecipient(is_string($userId) ? $userId : null, $target, 'transfer', $vault->name);
        }

        $this->audit->transaction(function () use ($actor, $target, $personal, $custodian, $personalDecisions, $custodianDecisions): void {
            foreach ($personal as $vault) {
                $item = $personalDecisions[$vault->id];
                $decision = (string) $item['decision'];
                if ($decision === 'convert') {
                    $this->convertToSharedVault($actor, $target, $vault, (string) $item['custodian_user_id'], (string) $item['manager_user_id']);
                } elseif ($decision === 'archive') {
                    $this->archiveVault($actor, $target, $vault);
                } else {
                    $this->purgeVault($actor, $target, $vault);
                }
            }

            foreach ($custodian as $vault) {
                $userId = $custodianDecisions[$vault->id]['transfer_user_id'] ?? null;
                $newCustodian = $this->activeRecipient(is_string($userId) ? $userId : null, $target, 'transfer', $vault->name);
                DB::table('vaults')->where('id', $vault->id)->update([
                    'primary_custodian_user_id' => $newCustodian->id,
                    'updated_at' => now(),
                ]);
                $this->audit->record('vault.custodian_changed', $actor->id, 'success', ['vault_id' => (string) $vault->id, 'target_type' => 'vault', 'target_id' => (string) $vault->id]);
            }

            $this->deactivate($target);
            app(EmergencyAccess::class)->revokeFor($target->id, $actor->id);
            $this->audit->record('user.offboarded', $actor->id);
        });
    }

    /**
     * Turn a departing owner's personal work vault into an ordinary shared vault
     * governed by a new Custodian and holding a Manager grant for someone who
     * should keep using it. Converting preserves every entry and folder.
     */
    private function convertToSharedVault(User $actor, User $target, Vault $vault, string $custodianUserId, string $managerUserId): void
    {
        if ($vault->state === 'pending_purge') {
            throw ValidationException::withMessages(['personal' => __('vaults.errors.pending_purge_state_change')]);
        }

        $custodian = $this->activeRecipient($custodianUserId, $target, 'custodian', $vault->name);
        $manager = $this->activeRecipient($managerUserId, $target, 'manager', $vault->name);

        DB::table('vaults')->where('id', $vault->id)->update([
            'kind' => Vault::KIND_SHARED,
            'private_workspace_owner_user_id' => null,
            'primary_custodian_user_id' => $custodian->id,
            'state' => 'active',
            'updated_at' => now(),
        ]);

        $this->revokePrincipalGrants($actor, $vault->id, $target);
        $this->grantManager($actor, $vault->id, $manager, 'offboarding conversion');
        $this->audit->record('vault.converted', $actor->id, 'success', ['vault_id' => (string) $vault->id, 'target_type' => 'vault', 'target_id' => (string) $vault->id]);
    }

    private function archiveVault(User $actor, User $target, Vault $vault): void
    {
        if ($vault->state === 'pending_purge') {
            throw ValidationException::withMessages(['personal' => __('vaults.errors.pending_purge_state_change')]);
        }

        DB::table('vaults')->where('id', $vault->id)->update(['state' => 'archived', 'updated_at' => now()]);
        $this->revokePrincipalGrants($actor, $vault->id, $target);
        $this->audit->record('vault.archived', $actor->id, 'success', ['vault_id' => (string) $vault->id, 'target_type' => 'vault', 'target_id' => (string) $vault->id]);
    }

    private function purgeVault(User $actor, User $target, Vault $vault): void
    {
        if ($vault->state === 'pending_purge') {
            throw ValidationException::withMessages(['personal' => __('vaults.errors.already_pending_purge')]);
        }

        app(VaultAdministration::class)->markForPurge($actor, $vault);
        $this->revokePrincipalGrants($actor, $vault->id, $target);
    }

    /**
     * Resolve an active account other than the one being offboarded, throwing a
     * field-scoped validation error when the choice is missing or invalid.
     */
    private function activeRecipient(?string $userId, User $target, string $role, string $vaultName): User
    {
        if ($userId === null || $userId === '') {
            throw ValidationException::withMessages([$role => __('offboarding.errors.'.$role.'_required', ['vault' => $vaultName])]);
        }

        $user = User::query()->whereKey($userId)->where('is_active', true)->first();
        if ($user === null || $user->id === $target->id) {
            throw ValidationException::withMessages([$role => __('offboarding.errors.'.$role.'_invalid')]);
        }

        return $user;
    }

    private function grantManager(User $actor, string $vaultId, User $manager, string $reason): void
    {
        DB::table('vault_grants')
            ->where('vault_id', $vaultId)
            ->where('principal_id', $manager->principal_id)
            ->whereNotNull('active_slot')
            ->update([
                'active_slot' => null,
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->id,
                'revocation_reason' => $reason,
                'updated_at' => now(),
            ]);

        DB::table('vault_grants')->insert([
            'id' => (string) Str::uuid(),
            'principal_id' => $manager->principal_id,
            'vault_id' => $vaultId,
            'permission' => 'manager',
            'source' => 'manual',
            'reason' => $reason,
            'granted_by_user_id' => $actor->id,
            'active_slot' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function revokePrincipalGrants(User $actor, string $vaultId, User $target): void
    {
        $revocation = [
            'active_slot' => null,
            'revoked_at' => now(),
            'revoked_by_user_id' => $actor->id,
            'revocation_reason' => 'offboarding',
            'updated_at' => now(),
        ];

        DB::table('vault_grants')
            ->where('vault_id', $vaultId)
            ->where('principal_id', $target->principal_id)
            ->whereNotNull('active_slot')
            ->update($revocation);

        $folderGrantIds = DB::table('folder_grants')
            ->join('folders', 'folders.id', '=', 'folder_grants.folder_id')
            ->where('folders.vault_id', $vaultId)
            ->where('folder_grants.principal_id', $target->principal_id)
            ->whereNotNull('folder_grants.active_slot')
            ->pluck('folder_grants.id')
            ->all();
        if ($folderGrantIds !== []) {
            DB::table('folder_grants')->whereIn('id', $folderGrantIds)->update($revocation);
        }

        $entryGrantIds = DB::table('entry_grants')
            ->join('entries', 'entries.id', '=', 'entry_grants.entry_id')
            ->where('entries.vault_id', $vaultId)
            ->where('entry_grants.principal_id', $target->principal_id)
            ->whereNotNull('entry_grants.active_slot')
            ->pluck('entry_grants.id')
            ->all();
        if ($entryGrantIds !== []) {
            DB::table('entry_grants')->whereIn('id', $entryGrantIds)->update($revocation);
        }
    }

    private function deactivate(User $target): void
    {
        $target->forceFill(['is_active' => false])->save();

        DB::table('users')->where('id', $target->id)->update(['remember_token' => null]);
        DB::table('sessions')->where('user_id', $target->id)->delete();
        DB::table('trusted_devices')->where('user_id', $target->id)->delete();
        DB::table('password_reset_tokens')->where('email', $target->email)->delete();
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<string, array<string, mixed>>
     */
    private function indexDecisions(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $vaultId = $item['vault_id'] ?? null;
            if (is_string($vaultId) && $vaultId !== '') {
                $indexed[$vaultId] = $item;
            }
        }

        return $indexed;
    }
}
