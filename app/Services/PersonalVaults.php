<?php

namespace App\Services;

use App\Models\User;
use App\Services\Crypto\VaultKeyManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provisions the one organization-managed personal work vault per user.
 *
 * A personal work vault is not private from the trusted server operator and
 * remains subject to organization retention and emergency access.
 */
final class PersonalVaults
{
    public function __construct(
        private readonly AuditWriter $audit,
        private readonly VaultKeyManager $keys,
    ) {}

    public function provisionFor(User $owner): string
    {
        return $this->audit->transaction(function () use ($owner): string {
            $existing = DB::table('vaults')->where('private_workspace_owner_user_id', $owner->id)->value('id');
            if ($existing !== null) {
                return (string) $existing;
            }

            $id = (string) Str::uuid();
            $name = (string) __('vaults.personal_vault.name', ['name' => mb_substr($owner->name, 0, 80)]);
            DB::table('vaults')->insert([
                'id' => $id,
                'name' => $name,
                'normalized_name' => Str::lower($name),
                'slug' => 'personal-work-vault-'.substr(hash('sha256', $owner->id), 0, 16),
                'description' => (string) __('vaults.personal_vault.description'),
                'icon' => 'user',
                'kind' => 'private_workspace',
                'private_workspace_owner_user_id' => $owner->id,
                'primary_custodian_user_id' => $owner->id,
                'state' => 'active',
                'created_by_user_id' => $owner->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->keys->createFirstVersion($id, $owner->id);
            DB::table('vault_grants')->insert([
                'id' => (string) Str::uuid(),
                'principal_id' => $owner->principal_id,
                'vault_id' => $id,
                'permission' => 'manager',
                'source' => 'manual',
                'granted_by_user_id' => $owner->id,
                'active_slot' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('vault.created', $owner->id, 'success', ['vault_id' => $id, 'target_type' => 'vault', 'target_id' => $id]);

            return $id;
        });
    }
}
