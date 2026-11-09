<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Collection;

/**
 * Per-user favourites over vaults and entries.
 *
 * A favourite is a personal shortcut, not a grant: it never widens access.
 * Every read re-filters through the canonical permission resolver, so a
 * favourite whose vault or entry has become unreachable, or that has been
 * trashed, simply disappears from lists and navigation.
 */
final class Favorites
{
    public function __construct(
        private readonly VaultAccess $access,
        private readonly EntryVault $entries,
    ) {}

    public function isVaultFavorite(User $user, string $vaultId): bool
    {
        return $user->favoriteVaults()->where('vaults.id', $vaultId)->exists();
    }

    public function isEntryFavorite(User $user, string $entryId): bool
    {
        return $user->favoriteEntries()->where('entries.id', $entryId)->exists();
    }

    /**
     * Toggle the favourite flag for one vault the user may currently see.
     *
     * @return bool the new state: true when the vault is now a favourite
     */
    public function toggleVault(User $user, string $vaultId): bool
    {
        abort_unless($this->access->allows($user, $vaultId, 'metadata.view'), 404);

        $result = $user->favoriteVaults()->toggle($vaultId);

        return $result['attached'] !== [];
    }

    /**
     * Toggle the favourite flag for one entry the user may currently see.
     *
     * @return bool the new state: true when the entry is now a favourite
     */
    public function toggleEntry(User $user, string $entryId): bool
    {
        $this->entries->find($user, $entryId);

        $result = $user->favoriteEntries()->toggle($entryId);

        return $result['attached'] !== [];
    }

    /**
     * The user's favourite vaults they may still see, ordered by name.
     *
     * @return Collection<int, Vault>
     */
    public function favoriteVaults(User $user): Collection
    {
        return $user->favoriteVaults()
            ->with('image:vault_id,checksum')
            ->whereIn('vaults.id', $this->access->visibleVaults($user)->select('vaults.id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * The user's favourite entries they may still see, ordered by title.
     *
     * @return Collection<int, Entry>
     */
    public function favoriteEntries(User $user): Collection
    {
        return $user->favoriteEntries()
            ->with(['vault.image:vault_id,checksum', 'folder', 'siteIcon:host,checksum'])
            ->whereNull('entries.deleted_at')
            ->get()
            ->filter(fn (Entry $entry): bool => $this->access->allowsOnEntry($user, $entry, 'metadata.view'))
            ->sortBy('title')
            ->values();
    }
}
