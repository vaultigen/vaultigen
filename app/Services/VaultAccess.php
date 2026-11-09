<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\Folder;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The canonical, deny-by-default permission resolver for vault access.
 *
 * Platform roles never grant data access. Grants are additive: the maximum
 * active permission across direct-user and team grants wins, and expired or
 * revoked grants are ignored on every request. A grant on a vault, a folder, or
 * an entry adds access; it can never reduce an inherited vault grant. A vault
 * pending purge grants nothing to anyone, while its grants stay intact so that
 * a Super Admin can restore it.
 */
final class VaultAccess
{
    public const LEVELS = ['viewer' => 1, 'editor' => 2, 'manager' => 3];

    /**
     * The minimum vault permission each data ability requires. Policies and
     * services check these abilities rather than scattering role names.
     *
     * `entry.*` and `secret.reveal` are resolved per target through
     * allowsOnEntry(); the vault-level mapping here is the vault-scope floor.
     *
     * @var array<string, string>
     */
    public const DATA_ABILITIES = [
        'metadata.view' => 'viewer',
        'secret.reveal' => 'viewer',
        'entry.create' => 'editor',
        'entry.update' => 'editor',
        'entry.move' => 'editor',
        'entry.delete' => 'manager',
        'entry.restore' => 'manager',
        'entry.history' => 'manager',
        'folder.create' => 'manager',
        'folder.rename' => 'manager',
        'folder.delete' => 'manager',
        'grant.create' => 'manager',
        'grant.revoke' => 'manager',
    ];

    /**
     * @return array{permission: string, expires_at: Carbon|null}|null
     */
    public function authority(User $user, string $vaultId): ?array
    {
        if (! $user->is_active || ! $this->isUuid($vaultId)) {
            return null;
        }

        return $this->summarize($this->activeGrants($user, [$vaultId]))[$vaultId] ?? null;
    }

    /**
     * Batch form of authority() that resolves many vaults with a single grant query.
     *
     * @param  list<string>  $vaultIds
     * @return array<string, array{permission: string, expires_at: Carbon|null}>
     */
    public function authorities(User $user, array $vaultIds): array
    {
        $vaultIds = array_values(array_filter($vaultIds, fn (string $id): bool => $this->isUuid($id)));

        if (! $user->is_active || $vaultIds === []) {
            return [];
        }

        return $this->summarize($this->activeGrants($user, $vaultIds));
    }

    public function permission(User $user, string $vaultId): ?string
    {
        return $this->authority($user, $vaultId)['permission'] ?? null;
    }

    public function can(User $user, string $vaultId, string $permission): bool
    {
        if (! isset(self::LEVELS[$permission])) {
            throw new InvalidArgumentException("Unknown vault permission [{$permission}].");
        }

        $effective = $this->permission($user, $vaultId);

        return $effective !== null && self::LEVELS[$effective] >= self::LEVELS[$permission];
    }

    /**
     * Check a named vault-level data ability against the canonical resolver.
     *
     * `metadata.view` succeeds when the user holds any active grant in the
     * vault (vault, folder, or entry), so a folder-scoped user can open the
     * vault and see exactly their granted entries.
     */
    public function allows(User $user, string $vaultId, string $ability): bool
    {
        if (! isset(self::DATA_ABILITIES[$ability])) {
            throw new InvalidArgumentException("Unknown vault ability [{$ability}].");
        }

        if ($ability === 'metadata.view') {
            return $this->hasVaultAccess($user, $vaultId);
        }

        if ($ability === 'secret.reveal' && $this->hasEmergencyAccess($user, $vaultId)) {
            return true;
        }

        $effective = $this->permission($user, $vaultId);

        return $effective !== null && self::LEVELS[$effective] >= self::LEVELS[self::DATA_ABILITIES[$ability]];
    }

    /**
     * Check an ability against one entry, honouring vault, folder, and entry grants.
     */
    public function allowsOnEntry(User $user, Entry $entry, string $ability): bool
    {
        if (! isset(self::DATA_ABILITIES[$ability])) {
            throw new InvalidArgumentException("Unknown vault ability [{$ability}].");
        }

        if (in_array($ability, ['metadata.view', 'secret.reveal'], true) && $this->hasEmergencyAccess($user, (string) $entry->vault_id)) {
            return true;
        }

        return $this->levelAllows($this->entryAuthority($user, $entry), self::DATA_ABILITIES[$ability]);
    }

    /**
     * Check an ability against one folder, honouring vault and folder grants.
     */
    public function allowsOnFolder(User $user, string $folderId, string $ability): bool
    {
        if (! isset(self::DATA_ABILITIES[$ability])) {
            throw new InvalidArgumentException("Unknown vault ability [{$ability}].");
        }

        if (! $this->isUuid($folderId)) {
            return false;
        }

        return $this->levelAllows($this->folderAuthority($user, $folderId), self::DATA_ABILITIES[$ability]);
    }

    /**
     * The effective permission on a folder: the maximum of the vault grant and
     * any folder grant.
     *
     * @return array{permission: string, expires_at: Carbon|null}|null
     */
    public function folderAuthority(User $user, string $folderId): ?array
    {
        if (! $user->is_active || ! $this->isUuid($folderId)) {
            return null;
        }

        $vaultId = DB::table('folders')->where('id', $folderId)->whereNull('deleted_at')->value('vault_id');
        if ($vaultId === null || $this->isPendingPurge((string) $vaultId)) {
            return null;
        }

        return $this->combine([
            ...$this->activeGrants($user, [(string) $vaultId]),
            ...$this->grantsOn($user, 'folder_grants', 'folder_id', [$folderId]),
        ]);
    }

    /**
     * The effective permission on an entry: the maximum of the vault grant, the
     * entry's folder grant, and any entry grant.
     *
     * @return array{permission: string, expires_at: Carbon|null}|null
     */
    public function entryAuthority(User $user, Entry $entry): ?array
    {
        if (! $user->is_active || $this->isPendingPurge((string) $entry->vault_id)) {
            return null;
        }

        return $this->combine([
            ...$this->activeGrants($user, [(string) $entry->vault_id]),
            ...($entry->folder_id === null ? [] : $this->grantsOn($user, 'folder_grants', 'folder_id', [(string) $entry->folder_id])),
            ...$this->grantsOn($user, 'entry_grants', 'entry_id', [(string) $entry->id]),
        ]);
    }

    /**
     * Whether the actor may create an entry at the given placement. A null
     * folder requires vault-scope editor authority; a folder may be authorized
     * by a vault grant or a folder grant, and must belong to the vault.
     */
    public function canCreateEntry(User $user, string $vaultId, ?string $folderId): bool
    {
        if (! $this->isUuid($vaultId)) {
            return false;
        }

        if ($folderId === null) {
            return $this->allows($user, $vaultId, 'entry.create');
        }

        if (! $this->isUuid($folderId)) {
            return false;
        }

        if (DB::table('folders')->where('id', $folderId)->where('vault_id', $vaultId)->whereNull('deleted_at')->doesntExist()) {
            return false;
        }

        return $this->levelAllows($this->folderAuthority($user, $folderId), 'editor');
    }

    /**
     * Whether the actor may create an entry anywhere in the vault: at the root
     * with vault-scope editor authority, or in at least one folder they hold a
     * folder grant on.
     */
    public function canCreateInVault(User $user, string $vaultId): bool
    {
        if ($this->canCreateEntry($user, $vaultId, null)) {
            return true;
        }

        foreach ($this->visibleFolders($user, $vaultId)->get() as $folder) {
            if ($this->allowsOnFolder($user, (string) $folder->id, 'entry.create')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the user holds any active vault-level grant. Folder and entry
     * grants are deliberately excluded: this is the "vault scope" fact used to
     * decide whether the actor may browse every folder and place entries at root.
     */
    public function hasVaultScope(User $user, string $vaultId): bool
    {
        if (! $user->is_active || ! $this->isUuid($vaultId) || $this->isPendingPurge($vaultId)) {
            return false;
        }

        return DB::table('vault_grants')
            ->where('vault_id', $vaultId)
            ->whereNotNull('active_slot')
            ->whereNull('revoked_at')
            ->where(fn (QueryBuilder $expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn (QueryBuilder $principal) => $this->principalConstraint($principal, $user, 'vault_grants'))
            ->exists();
    }

    /**
     * An active emergency administrator session for exactly this vault. It
     * permits metadata view and individual secret reveal only, and only while
     * the user still qualifies to administer the installation.
     */
    public function hasEmergencyAccess(User $user, string $vaultId): bool
    {
        if (! $user->is_active || ! $this->isUuid($vaultId) || $this->isPendingPurge($vaultId)) {
            return false;
        }

        return DB::table('emergency_access_sessions')
            ->where('super_admin_user_id', $user->id)
            ->where('vault_id', $vaultId)
            ->whereNull('closed_at')
            ->where('expires_at', '>', now())
            ->exists() && $this->mayUseEmergencyAccess($user);
    }

    /**
     * Whether the user holds any active grant (vault, folder, or entry) in the
     * vault. A folder-scoped user may open the vault and browse their scope.
     */
    public function hasVaultAccess(User $user, string $vaultId): bool
    {
        if (! $user->is_active || ! $this->isUuid($vaultId) || $this->isPendingPurge($vaultId)) {
            return false;
        }

        if ($this->hasEmergencyAccess($user, $vaultId)) {
            return true;
        }

        if ($this->hasVaultScope($user, $vaultId)) {
            return true;
        }

        $folderGrant = DB::table('folder_grants')
            ->join('folders', 'folders.id', '=', 'folder_grants.folder_id')
            ->where('folders.vault_id', $vaultId)
            ->whereNull('folders.deleted_at')
            ->whereNotNull('folder_grants.active_slot')
            ->whereNull('folder_grants.revoked_at')
            ->where(fn (QueryBuilder $expiry) => $expiry->whereNull('folder_grants.expires_at')->orWhere('folder_grants.expires_at', '>', now()))
            ->where(fn (QueryBuilder $principal) => $this->principalConstraint($principal, $user, 'folder_grants'))
            ->exists();
        if ($folderGrant) {
            return true;
        }

        return DB::table('entry_grants')
            ->join('entries', 'entries.id', '=', 'entry_grants.entry_id')
            ->where('entries.vault_id', $vaultId)
            ->whereNull('entries.deleted_at')
            ->whereNotNull('entry_grants.active_slot')
            ->whereNull('entry_grants.revoked_at')
            ->where(fn (QueryBuilder $expiry) => $expiry->whereNull('entry_grants.expires_at')->orWhere('entry_grants.expires_at', '>', now()))
            ->where(fn (QueryBuilder $principal) => $this->principalConstraint($principal, $user, 'entry_grants'))
            ->exists();
    }

    /**
     * The contributing active grants for the "Why can I see this?" explanation,
     * across vault, folder, and entry scope. Scoped to the given principal.
     *
     * @return list<array{principal_id: string, kind: string, label: string, permission: string, scope: string, scope_id: string, scope_label: string, expires_at: Carbon|null}>
     */
    public function explain(User $user, string $vaultId): array
    {
        if (! $user->is_active || ! $this->isUuid($vaultId) || $this->isPendingPurge($vaultId)) {
            return [];
        }

        $vault = DB::table('vaults')->where('id', $vaultId)->first();
        $vaultName = $vault === null ? (string) __('vaults.labels.vault') : (string) $vault->name;
        $rows = [];
        foreach ($this->activeGrants($user, [$vaultId]) as $grant) {
            $rows[] = ['grant' => $grant, 'scope' => 'vault', 'scope_id' => $vaultId, 'scope_label' => $vaultName];
        }
        foreach ($this->grantsOn($user, 'folder_grants', 'folder_id', $this->folderIds($vaultId)) as $grant) {
            $rows[] = ['grant' => $grant, 'scope' => 'folder', 'scope_id' => (string) $grant->folder_id, 'scope_label' => (string) $grant->scope_label];
        }
        foreach ($this->grantsOn($user, 'entry_grants', 'entry_id', $this->entryIds($vaultId)) as $grant) {
            $rows[] = ['grant' => $grant, 'scope' => 'entry', 'scope_id' => (string) $grant->entry_id, 'scope_label' => (string) $grant->scope_label];
        }

        return $this->labelRows($rows);
    }

    /**
     * Every active vault-level grant on the vault, across all principals. These
     * holders can reach every folder and entry in the vault, so a Manager uses
     * this to explain access that does not come from a grant on one target.
     *
     * @return list<array{principal_id: string, kind: string, label: string, permission: string, scope: string, scope_id: string, scope_label: string, expires_at: Carbon|null}>
     */
    public function vaultGrantHolders(string $vaultId): array
    {
        if (! $this->isUuid($vaultId)) {
            return [];
        }

        $vault = DB::table('vaults')->where('id', $vaultId)->first();
        $vaultName = $vault === null ? (string) __('vaults.labels.vault') : (string) $vault->name;

        $rows = [];
        foreach ($this->allActiveVaultGrants($vaultId) as $grant) {
            $rows[] = ['grant' => $grant, 'scope' => 'vault', 'scope_id' => $vaultId, 'scope_label' => $vaultName];
        }

        return $this->labelRows($rows);
    }

    /**
     * @param  list<array{grant: \stdClass, scope: string, scope_id: string, scope_label: string}>  $rows
     * @return list<array{principal_id: string, kind: string, label: string, permission: string, scope: string, scope_id: string, scope_label: string, expires_at: Carbon|null}>
     */
    private function labelRows(array $rows): array
    {
        $labels = app(PrincipalDirectory::class)->labels(array_map(
            fn (array $row): string => (string) $row['grant']->principal_id,
            $rows,
        ));

        return array_map(function (array $row) use ($labels): array {
            $grant = $row['grant'];
            $principalId = (string) $grant->principal_id;

            return [
                'principal_id' => $principalId,
                'kind' => $labels[$principalId]['kind'] ?? 'unknown',
                'label' => $labels[$principalId]['label'] ?? $principalId,
                'permission' => (string) $grant->permission,
                'scope' => $row['scope'],
                'scope_id' => $row['scope_id'],
                'scope_label' => $row['scope_label'],
                'expires_at' => $grant->expires_at === null ? null : Carbon::parse((string) $grant->expires_at),
            ];
        }, $rows);
    }

    /**
     * Vaults the user may currently see, as an EXISTS query over canonical
     * grants. Folder and entry grants make their vault visible.
     *
     * @return Builder<Vault>
     */
    public function visibleVaults(User $user, ?string $exactPermission = null): Builder
    {
        if (! $user->is_active) {
            return Vault::query()->whereRaw('1 = 0');
        }

        $mayUseEmergencyAccess = $exactPermission === null && $this->mayUseEmergencyAccess($user);

        return Vault::query()->where('vaults.state', '!=', 'pending_purge')->where(function (Builder $query) use ($user, $exactPermission, $mayUseEmergencyAccess): void {
            $query->whereExists(function (QueryBuilder $sub) use ($user, $exactPermission): void {
                $sub->select(DB::raw('1'))
                    ->from('vault_grants')
                    ->whereColumn('vault_grants.vault_id', 'vaults.id')
                    ->whereNotNull('vault_grants.active_slot')
                    ->whereNull('vault_grants.revoked_at')
                    ->where(fn (QueryBuilder $expiry) => $expiry->whereNull('vault_grants.expires_at')->orWhere('vault_grants.expires_at', '>', now()))
                    ->when($exactPermission !== null, fn (QueryBuilder $sub) => $sub->where('vault_grants.permission', $exactPermission))
                    ->where(fn (QueryBuilder $principal) => $this->principalConstraint($principal, $user, 'vault_grants'));
            });

            // Folder and entry grants only widen visibility for the unrestricted
            // view; an exact-permission request (for example "vaults I manage")
            // stays a vault-scope question.
            if ($exactPermission !== null) {
                return;
            }

            $query->orWhereExists(function (QueryBuilder $sub) use ($user, $mayUseEmergencyAccess): void {
                $sub->select(DB::raw('1'))
                    ->from('emergency_access_sessions')
                    ->when(! $mayUseEmergencyAccess, fn (QueryBuilder $sub) => $sub->whereRaw('1 = 0'))
                    ->whereColumn('emergency_access_sessions.vault_id', 'vaults.id')
                    ->where('emergency_access_sessions.super_admin_user_id', $user->id)
                    ->whereNull('emergency_access_sessions.closed_at')
                    ->where('emergency_access_sessions.expires_at', '>', now());
            })->orWhereExists(function (QueryBuilder $sub) use ($user): void {
                $sub->select(DB::raw('1'))
                    ->from('folder_grants')
                    ->join('folders', 'folders.id', '=', 'folder_grants.folder_id')
                    ->whereColumn('folders.vault_id', 'vaults.id')
                    ->whereNull('folders.deleted_at')
                    ->whereNotNull('folder_grants.active_slot')
                    ->whereNull('folder_grants.revoked_at')
                    ->where(fn (QueryBuilder $expiry) => $expiry->whereNull('folder_grants.expires_at')->orWhere('folder_grants.expires_at', '>', now()))
                    ->where(fn (QueryBuilder $principal) => $this->principalConstraint($principal, $user, 'folder_grants'));
            })->orWhereExists(function (QueryBuilder $sub) use ($user): void {
                $sub->select(DB::raw('1'))
                    ->from('entry_grants')
                    ->join('entries', 'entries.id', '=', 'entry_grants.entry_id')
                    ->whereColumn('entries.vault_id', 'vaults.id')
                    ->whereNull('entries.deleted_at')
                    ->whereNotNull('entry_grants.active_slot')
                    ->whereNull('entry_grants.revoked_at')
                    ->where(fn (QueryBuilder $expiry) => $expiry->whereNull('entry_grants.expires_at')->orWhere('entry_grants.expires_at', '>', now()))
                    ->where(fn (QueryBuilder $principal) => $this->principalConstraint($principal, $user, 'entry_grants'));
            });
        });
    }

    /**
     * Folders the user may browse in a vault: every folder for a vault-scope
     * holder, otherwise exactly the folders with an active folder grant.
     *
     * @return Builder<Folder>
     */
    public function visibleFolders(User $user, string $vaultId): Builder
    {
        if (! $this->isUuid($vaultId) || $this->isPendingPurge($vaultId)) {
            return Folder::query()->whereRaw('1 = 0');
        }

        $query = Folder::query()->where('vault_id', $vaultId)->whereNull('deleted_at');

        if (! $this->hasVaultScope($user, $vaultId) && ! $this->hasEmergencyAccess($user, $vaultId)) {
            $query->whereExists(function (QueryBuilder $sub) use ($user): void {
                $sub->select(DB::raw('1'))
                    ->from('folder_grants')
                    ->whereColumn('folder_grants.folder_id', 'folders.id')
                    ->whereNotNull('folder_grants.active_slot')
                    ->whereNull('folder_grants.revoked_at')
                    ->where(fn (QueryBuilder $expiry) => $expiry->whereNull('folder_grants.expires_at')->orWhere('folder_grants.expires_at', '>', now()))
                    ->where(fn (QueryBuilder $principal) => $this->principalConstraint($principal, $user, 'folder_grants'));
            });
        }

        return $query;
    }

    /**
     * Entries the user may see in a vault, permission-scoped before any filter
     * or search is applied.
     *
     * @return Builder<Entry>
     */
    public function visibleEntries(User $user, string $vaultId): Builder
    {
        if (! $this->isUuid($vaultId) || $this->isPendingPurge($vaultId)) {
            return Entry::query()->whereRaw('1 = 0');
        }

        $mayUseEmergencyAccess = $this->mayUseEmergencyAccess($user);

        return Entry::query()
            ->where('entries.vault_id', $vaultId)
            ->whereNull('entries.deleted_at')
            ->where(function (Builder $query) use ($user, $vaultId, $mayUseEmergencyAccess): void {
                $query->whereExists(function (QueryBuilder $sub) use ($user, $vaultId): void {
                    $sub->select(DB::raw('1'))
                        ->from('vault_grants')
                        ->where('vault_grants.vault_id', $vaultId)
                        ->whereNotNull('vault_grants.active_slot')
                        ->whereNull('vault_grants.revoked_at')
                        ->where(fn (QueryBuilder $expiry) => $expiry->whereNull('vault_grants.expires_at')->orWhere('vault_grants.expires_at', '>', now()))
                        ->where(fn (QueryBuilder $principal) => $this->principalConstraint($principal, $user, 'vault_grants'));
                })->orWhere(function (Builder $query) use ($user): void {
                    $query->whereNotNull('entries.folder_id')->whereExists(function (QueryBuilder $sub) use ($user): void {
                        $sub->select(DB::raw('1'))
                            ->from('folder_grants')
                            ->whereColumn('folder_grants.folder_id', 'entries.folder_id')
                            ->whereNotNull('folder_grants.active_slot')
                            ->whereNull('folder_grants.revoked_at')
                            ->where(fn (QueryBuilder $expiry) => $expiry->whereNull('folder_grants.expires_at')->orWhere('folder_grants.expires_at', '>', now()))
                            ->where(fn (QueryBuilder $principal) => $this->principalConstraint($principal, $user, 'folder_grants'));
                    });
                })->orWhereExists(function (QueryBuilder $sub) use ($user): void {
                    $sub->select(DB::raw('1'))
                        ->from('entry_grants')
                        ->whereColumn('entry_grants.entry_id', 'entries.id')
                        ->whereNotNull('entry_grants.active_slot')
                        ->whereNull('entry_grants.revoked_at')
                        ->where(fn (QueryBuilder $expiry) => $expiry->whereNull('entry_grants.expires_at')->orWhere('entry_grants.expires_at', '>', now()))
                        ->where(fn (QueryBuilder $principal) => $this->principalConstraint($principal, $user, 'entry_grants'));
                })->orWhereExists(function (QueryBuilder $sub) use ($user, $vaultId, $mayUseEmergencyAccess): void {
                    $sub->select(DB::raw('1'))
                        ->from('emergency_access_sessions')
                        ->when(! $mayUseEmergencyAccess, fn (QueryBuilder $sub) => $sub->whereRaw('1 = 0'))
                        ->where('emergency_access_sessions.vault_id', $vaultId)
                        ->where('emergency_access_sessions.super_admin_user_id', $user->id)
                        ->whereNull('emergency_access_sessions.closed_at')
                        ->where('emergency_access_sessions.expires_at', '>', now());
                });
            });
    }

    public function managesAnyVault(User $user): bool
    {
        return $this->visibleVaults($user, 'manager')->exists();
    }

    /**
     * Vaults whose lifecycle the user governs: all vaults for a Super Admin,
     * otherwise the shared vaults they are the Custodian of.
     *
     * @return Builder<Vault>
     */
    public function governedVaults(User $user): Builder
    {
        if (! $user->is_active) {
            return Vault::query()->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return Vault::query();
        }

        return Vault::query()->where('kind', Vault::KIND_SHARED)->where('primary_custodian_user_id', $user->id);
    }

    public function governsAnyVault(User $user): bool
    {
        return $this->governedVaults($user)->exists();
    }

    /**
     * Vaults the user can administer through the vault administration screen:
     * everything they govern, plus the vaults where they hold a Manager grant.
     *
     * @return Builder<Vault>
     */
    public function administeredVaults(User $user): Builder
    {
        if (! $user->is_active) {
            return Vault::query()->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            return Vault::query();
        }

        return Vault::query()->where(function (Builder $query) use ($user): void {
            $query->where(function (Builder $governed) use ($user): void {
                $governed->where('kind', Vault::KIND_SHARED)
                    ->where('primary_custodian_user_id', $user->id);
            })->orWhereIn('id', $this->visibleVaults($user, 'manager')->select('vaults.id'));
        });
    }

    public function isCustodian(User $user, string $vaultId): bool
    {
        return $user->is_active && $this->isUuid($vaultId) && DB::table('vaults')
            ->where('id', $vaultId)
            ->where('kind', Vault::KIND_SHARED)
            ->where('primary_custodian_user_id', $user->id)
            ->exists();
    }

    public function canGovern(User $user, string $vaultId): bool
    {
        return $user->isSuperAdmin() || $this->isCustodian($user, $vaultId);
    }

    /**
     * @return list<string>
     */
    public function principalIds(User $user): array
    {
        $teamPrincipals = DB::table('team_memberships')
            ->join('teams', 'teams.id', '=', 'team_memberships.team_id')
            ->where('team_memberships.user_id', $user->id)
            ->pluck('teams.principal_id')
            ->all();

        return array_values(array_map(fn (mixed $id): string => (string) $id, [
            $user->principal_id,
            ...$teamPrincipals,
        ]));
    }

    /**
     * Emergency sessions never outlive the authority that started them.
     */
    private function mayUseEmergencyAccess(User $user): bool
    {
        return Gate::forUser($user)->allows('manage-installation');
    }

    /**
     * @param  array{permission: string, expires_at: Carbon|null}|null  $authority
     */
    private function levelAllows(?array $authority, string $permission): bool
    {
        return $authority !== null && self::LEVELS[$authority['permission']] >= self::LEVELS[$permission];
    }

    /**
     * @param  list<string>  $vaultIds
     * @return list<\stdClass>
     */
    private function activeGrants(User $user, array $vaultIds): array
    {
        $vaultIds = array_values(array_filter($vaultIds, fn (string $id): bool => $this->isUuid($id)));

        return array_values(DB::table('vault_grants')
            ->whereIn('principal_id', $this->principalIds($user))
            ->whereIn('vault_id', $vaultIds)
            ->whereNotIn('vault_id', DB::table('vaults')->where('state', 'pending_purge')->select('id'))
            ->whereNotNull('active_slot')
            ->whereNull('revoked_at')
            ->where(fn (QueryBuilder $expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->all());
    }

    private function isPendingPurge(string $vaultId): bool
    {
        return DB::table('vaults')->where('id', $vaultId)->where('state', 'pending_purge')->exists();
    }

    /**
     * @return list<\stdClass>
     */
    private function allActiveVaultGrants(string $vaultId): array
    {
        return array_values(DB::table('vault_grants')
            ->where('vault_id', $vaultId)
            ->whereNotNull('active_slot')
            ->whereNull('revoked_at')
            ->where(fn (QueryBuilder $expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->all());
    }

    /**
     * Active grants on a typed target table, with the target name included as
     * `scope_label` for the grant-trail explanation.
     *
     * @param  list<string>  $targetIds
     * @return list<\stdClass>
     */
    private function grantsOn(User $user, string $table, string $column, array $targetIds): array
    {
        $targetIds = array_values(array_filter($targetIds, fn (string $id): bool => $this->isUuid($id)));

        if ($targetIds === []) {
            return [];
        }

        $targetTable = $table === 'folder_grants' ? 'folders' : 'entries';
        $labelColumn = $targetTable === 'folders' ? 'name' : 'title';

        return array_values(DB::table($table)
            ->join($targetTable, "{$targetTable}.id", '=', "{$table}.{$column}")
            ->whereIn("{$table}.principal_id", $this->principalIds($user))
            ->whereIn("{$table}.{$column}", $targetIds)
            ->whereNotNull("{$table}.active_slot")
            ->whereNull("{$table}.revoked_at")
            ->where(fn (QueryBuilder $expiry) => $expiry->whereNull("{$table}.expires_at")->orWhere("{$table}.expires_at", '>', now()))
            ->select("{$table}.*", "{$targetTable}.{$labelColumn} as scope_label")
            ->get()
            ->all());
    }

    /**
     * @return list<string>
     */
    private function folderIds(string $vaultId): array
    {
        return array_values(DB::table('folders')
            ->where('vault_id', $vaultId)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    /**
     * @return list<string>
     */
    private function entryIds(string $vaultId): array
    {
        return array_values(DB::table('entries')
            ->where('vault_id', $vaultId)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    /**
     * @param  list<\stdClass>  $grants
     * @return array{permission: string, expires_at: Carbon|null}|null
     */
    private function combine(array $grants): ?array
    {
        if ($grants === []) {
            return null;
        }

        usort($grants, fn (\stdClass $first, \stdClass $second): int => self::LEVELS[(string) $first->permission] <=> self::LEVELS[(string) $second->permission]);
        $permission = (string) $grants[array_key_last($grants)]->permission;

        $expiresAt = null;
        foreach ($grants as $grant) {
            if ((string) $grant->permission !== $permission) {
                continue;
            }
            if ($grant->expires_at === null) {
                $expiresAt = null;
                break;
            }
            $candidate = Carbon::parse((string) $grant->expires_at);
            $expiresAt = $expiresAt === null ? $candidate : $expiresAt->max($candidate);
        }

        return ['permission' => $permission, 'expires_at' => $expiresAt];
    }

    /**
     * Reduce raw grants to the winning (highest) permission and furthest expiry per vault.
     *
     * @param  list<\stdClass>  $grants
     * @return array<string, array{permission: string, expires_at: Carbon|null}>
     */
    private function summarize(array $grants): array
    {
        $grouped = [];
        foreach ($grants as $grant) {
            $grouped[(string) $grant->vault_id][] = $grant;
        }

        $summary = [];
        foreach ($grouped as $vaultId => $vaultGrants) {
            $combined = $this->combine($vaultGrants);
            if ($combined !== null) {
                $summary[$vaultId] = $combined;
            }
        }

        return $summary;
    }

    private function isUuid(?string $id): bool
    {
        return $id !== null && $id !== '' && Str::isUuid($id);
    }

    private function principalConstraint(QueryBuilder $query, User $user, string $table): void
    {
        $query->where(function (QueryBuilder $principal) use ($user, $table): void {
            $principal->where("{$table}.principal_id", $user->principal_id)
                ->orWhereIn("{$table}.principal_id", function (QueryBuilder $teams) use ($user): void {
                    $teams->select('teams.principal_id')
                        ->from('team_memberships')
                        ->join('teams', 'teams.id', '=', 'team_memberships.team_id')
                        ->where('team_memberships.user_id', $user->id);
                });
        });
    }
}
