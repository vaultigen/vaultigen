<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vault;
use App\Services\Crypto\VaultKeyManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Vault shell administration and lifecycle governance.
 *
 * Creating or editing a shell never grants the actor access to its contents;
 * access comes only from explicit grants. Governance abilities (rename, state,
 * custodian transfer, purge initiation) also never expose secret data.
 *
 * Marking a vault for purge ends all access at once. A Super Admin can restore
 * it until `purge_after`, after which the scheduled purge deletes it.
 */
final class VaultAdministration
{
    /** @var array<string, string> */
    public const ICONS = [
        'key' => 'Key',
        'briefcase' => 'Briefcase',
        'building-office-2' => 'Building',
        'shield-check' => 'Shield',
        'users' => 'Team',
        'globe-alt' => 'Globe',
        'server-stack' => 'Servers',
        'lock-closed' => 'Lock',
        'user' => 'Person',
    ];

    public const STATES = ['active', 'read_only', 'archived'];

    public function __construct(
        private readonly AuditWriter $audit,
        private readonly VaultKeyManager $keys,
        private readonly VaultAccess $access,
        private readonly InstallationSettings $settings,
        private readonly VaultImages $images,
        private readonly EmergencyAccess $emergencyAccess,
        private readonly SecurityNotifications $notifications,
    ) {}

    /**
     * Admin path: create a shell and name its Custodian and initial Manager.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): string
    {
        Gate::forUser($actor)->authorize('manage-installation');
        $shell = $this->validateShellFields($data);
        $custodianId = $this->validateCustodian($data);
        $initialManager = User::findOrFail($this->validateInitialManager($data));

        return $this->persist($actor, $shell, $custodianId, $initialManager, $this->uploadedImage($data));
    }

    /**
     * Self-service path: the creator becomes the initial Manager and default
     * Custodian through an explicit, audited grant, and receives no broader access.
     *
     * @param  array<string, mixed>  $data
     */
    public function createOwn(User $actor, array $data): string
    {
        Gate::forUser($actor)->authorize('create-vault');
        app(VaultCreationPolicy::class)->assertWithinQuota($actor);
        $shell = $this->validateShellFields($data);

        return $this->persist($actor, $shell, $actor->id, $actor, $this->uploadedImage($data));
    }

    /**
     * A new `image` upload replaces the vault's image; `remove_image` drops it.
     * The picked icon is kept either way and shows whenever there is no image.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, string $vaultId, array $data): void
    {
        $vault = $this->resolveVaultForGovernance($actor, $vaultId);
        $this->authorizeGovernor($actor, $vault, allowManager: true);
        $this->assertMetadataEditable($vault);
        $shell = $this->validateShellFields($data);
        $image = $this->uploadedImage($data);
        $removeImage = $image === null && (bool) ($data['remove_image'] ?? false);

        $this->audit->transaction(function () use ($actor, $vault, $shell, $image, $removeImage): void {
            if ($image !== null) {
                $this->images->store($vault->id, $image);
            } elseif ($removeImage) {
                $this->images->remove($vault->id);
            }
            DB::table('vaults')->where('id', $vault->id)->update([
                'name' => $shell['name'],
                'normalized_name' => Str::lower($shell['name']),
                'description' => $shell['description'],
                'icon' => $shell['icon'],
                'updated_at' => now(),
            ]);
            $this->audit->record('vault.updated', $actor->id, 'success', ['vault_id' => (string) $vault->id, 'target_type' => 'vault', 'target_id' => (string) $vault->id]);
        });
    }

    public function transferCustodian(User $actor, string $vaultId, string $custodianId): void
    {
        $vault = $this->resolveVaultForGovernance($actor, $vaultId);
        $this->authorizeGovernor($actor, $vault, requireCustodian: true);
        $this->assertMetadataEditable($vault);
        $custodian = User::findOrFail($this->validateCustodian(['primary_custodian_user_id' => $custodianId]));

        $this->audit->transaction(function () use ($actor, $vault, $custodian): void {
            DB::table('vaults')->where('id', $vault->id)->update([
                'primary_custodian_user_id' => $custodian->id,
                'updated_at' => now(),
            ]);
            $this->audit->record('vault.custodian_changed', $actor->id, 'success', ['vault_id' => (string) $vault->id, 'target_type' => 'vault', 'target_id' => (string) $vault->id]);
        });
    }

    public function setState(User $actor, string $vaultId, string $state): void
    {
        $vault = $this->resolveVaultForGovernance($actor, $vaultId);
        $this->authorizeGovernor($actor, $vault);
        Validator::make(['state' => $state], ['state' => ['required', Rule::in(self::STATES)]])->validate();
        abort_if($vault->state === 'pending_purge', 422, (string) __('vaults.errors.pending_purge_state_change'));
        if ($vault->state === $state) {
            throw ValidationException::withMessages(['state' => __('vaults.errors.already_in_state')]);
        }

        $action = match ($state) {
            'archived' => 'vault.archived',
            'active' => 'vault.reactivated',
            default => 'vault.updated',
        };

        $this->audit->transaction(function () use ($actor, $vault, $state, $action): void {
            DB::table('vaults')->where('id', $vault->id)->update(['state' => $state, 'updated_at' => now()]);
            $this->audit->record($action, $actor->id, 'success', ['vault_id' => (string) $vault->id, 'target_type' => 'vault', 'target_id' => (string) $vault->id]);
        });
    }

    public function initiatePurge(User $actor, string $vaultId): void
    {
        $vault = $this->resolveVaultForGovernance($actor, $vaultId);
        $this->authorizeGovernor($actor, $vault);
        abort_if($vault->state === 'pending_purge', 422, (string) __('vaults.errors.already_pending_purge'));

        $this->audit->transaction(fn () => $this->markForPurge($actor, $vault));
    }

    /**
     * Start a vault's purge waiting period. Runs inside the caller's audited
     * transaction.
     */
    public function markForPurge(User $actor, Vault $vault): void
    {
        DB::table('vaults')->where('id', $vault->id)->update([
            'state' => 'pending_purge',
            'purge_previous_state' => $vault->state,
            'purge_after' => now()->addDays($this->settings->values()['vault_purge_retention_days']),
            'updated_at' => now(),
        ]);
        $this->emergencyAccess->revokeForVault($vault->id, $actor->id);
        $sequence = $this->audit->record('vault.purge_initiated', $actor->id, 'success', ['vault_id' => (string) $vault->id, 'target_type' => 'vault', 'target_id' => (string) $vault->id]);

        $settings = $this->settings->values();
        if ($settings['vault_purge_notifications_enabled']) {
            $this->notifications->queue($sequence, $settings['security_recipients']);
        }
    }

    /**
     * Undo a purge during its waiting period: the vault returns to the state it
     * was marked from, with its contents and grants untouched.
     */
    public function cancelPurge(User $actor, string $vaultId): void
    {
        Gate::forUser($actor)->authorize('manage-installation');
        $vault = Vault::query()->findOrFail($vaultId);
        if ($vault->state !== 'pending_purge') {
            throw ValidationException::withMessages(['state' => __('vaults.errors.not_pending_purge')]);
        }

        $this->audit->transaction(function () use ($actor, $vault): void {
            DB::table('vaults')->where('id', $vault->id)->update([
                'state' => $vault->purge_previous_state ?? 'archived',
                'purge_previous_state' => null,
                'purge_after' => null,
                'updated_at' => now(),
            ]);
            $this->audit->record('vault.purge_cancelled', $actor->id, 'success', ['vault_id' => (string) $vault->id, 'target_type' => 'vault', 'target_id' => (string) $vault->id]);
        });
    }

    public function recover(User $actor, string $vaultId, string $managerUserId, string $reason, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code): void
    {
        Gate::forUser($actor)->authorize('manage-installation');
        Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'max:1000']])->validate();
        $vault = Vault::query()->findOrFail($vaultId);
        if ($vault->state === 'pending_purge') {
            throw ValidationException::withMessages(['reason' => __('vaults.errors.recover_pending_purge')]);
        }
        if ($this->activeManagerCount($vault->id) > 0) {
            throw ValidationException::withMessages(['reason' => __('vaults.errors.recovery_not_required')]);
        }
        $manager = User::findOrFail($this->validateInitialManager(['initial_manager_user_id' => $managerUserId]));
        app(StrongAuthentication::class)->confirm($actor, $password, $code);

        $this->audit->transaction(function () use ($actor, $vault, $manager, $reason): void {
            DB::table('vault_grants')->insert([
                'id' => (string) Str::uuid(),
                'principal_id' => $manager->principal_id,
                'vault_id' => $vault->id,
                'permission' => 'manager',
                'source' => 'manual',
                'reason' => trim($reason),
                'granted_by_user_id' => $actor->id,
                'active_slot' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('vault.recovered', $actor->id, 'success', ['vault_id' => (string) $vault->id, 'target_type' => 'vault', 'target_id' => (string) $vault->id]);
        });
    }

    /**
     * @param  array{name: string, description: string|null, icon: string|null}  $shell
     * @param  array{mime: string, bytes: string}|null  $image
     */
    private function persist(User $actor, array $shell, string $custodianId, User $initialManager, ?array $image = null): string
    {
        return $this->audit->transaction(function () use ($actor, $shell, $custodianId, $initialManager, $image): string {
            $id = (string) Str::uuid();
            DB::table('vaults')->insert([
                'id' => $id,
                'name' => $shell['name'],
                'normalized_name' => Str::lower($shell['name']),
                'slug' => $this->uniqueSlug($shell['name']),
                'description' => $shell['description'],
                'icon' => $shell['icon'],
                'kind' => 'shared',
                'primary_custodian_user_id' => $custodianId,
                'state' => 'active',
                'created_by_user_id' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->keys->createFirstVersion($id, $actor->id);
            if ($image !== null) {
                $this->images->store($id, $image);
            }
            DB::table('vault_grants')->insert([
                'id' => (string) Str::uuid(),
                'principal_id' => $initialManager->principal_id,
                'vault_id' => $id,
                'permission' => 'manager',
                'source' => 'manual',
                'granted_by_user_id' => $actor->id,
                'active_slot' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('vault.created', $actor->id, 'success', ['vault_id' => $id, 'target_type' => 'vault', 'target_id' => $id]);

            return $id;
        });
    }

    private function resolveVaultForGovernance(User $actor, string $vaultId): Vault
    {
        $vault = Vault::query()->find($vaultId);
        if ($vault === null) {
            // A missing vault is a 404 only for administrators; everyone else is denied
            // without learning whether the identifier exists.
            Gate::forUser($actor)->authorize('manage-installation');
            abort(404);
        }

        return $vault;
    }

    private function authorizeGovernor(User $actor, Vault $vault, bool $allowManager = false, bool $requireCustodian = false): void
    {
        if (Gate::forUser($actor)->allows('manage-installation')) {
            return;
        }
        if ($this->access->isCustodian($actor, $vault->id)) {
            return;
        }
        if (! $requireCustodian && $allowManager && $this->access->can($actor, $vault->id, 'manager')) {
            return;
        }

        throw new AuthorizationException((string) __('vaults.errors.not_allowed_to_govern'));
    }

    private function assertMetadataEditable(Vault $vault): void
    {
        if (! in_array($vault->state, ['active', 'read_only'], true)) {
            throw ValidationException::withMessages(['name' => __('vaults.errors.not_editable')]);
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

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, description: string|null, icon: string|null}
     */
    private function validateShellFields(array $data): array
    {
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', Rule::in(array_keys(self::ICONS))],
        ])->validate();

        return [
            'name' => (string) $validated['name'],
            'description' => isset($validated['description']) ? (string) $validated['description'] : null,
            'icon' => isset($validated['icon']) ? (string) $validated['icon'] : null,
        ];
    }

    /**
     * Normalize an optional `image` upload; the temporary file is discarded.
     *
     * @param  array<string, mixed>  $data
     * @return array{mime: string, bytes: string}|null
     */
    private function uploadedImage(array $data): ?array
    {
        $file = $data['image'] ?? null;
        if ($file === null) {
            return null;
        }
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw ValidationException::withMessages(['image' => __('vaults.errors.invalid_image')]);
        }

        try {
            return $this->images->normalize((string) $file->get());
        } finally {
            $file instanceof TemporaryUploadedFile ? $file->delete() : @unlink($file->getRealPath());
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateCustodian(array $data): string
    {
        return (string) Validator::make($data, [
            'primary_custodian_user_id' => ['required', 'uuid', 'exists:users,id'],
        ])->validate()['primary_custodian_user_id'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateInitialManager(array $data): string
    {
        return (string) Validator::make($data, [
            'initial_manager_user_id' => ['required', 'uuid', 'exists:users,id'],
        ])->validate()['initial_manager_user_id'];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'vault';
        }

        $slug = $base;
        $suffix = 2;
        while (DB::table('vaults')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
