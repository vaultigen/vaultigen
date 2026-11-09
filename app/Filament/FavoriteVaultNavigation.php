<?php

namespace App\Filament;

use App\Filament\Pages\VaultEntries;
use App\Models\User;
use App\Models\Vault;
use App\Services\Favorites;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\HtmlString;

use function Filament\Support\original_request;

/**
 * Builds the top-level navigation shortcuts for a user's favourite vaults.
 *
 * They are rendered ungrouped just below "My vaults" (sort 1) and only ever
 * include vaults the user may still reach.
 */
final class FavoriteVaultNavigation
{
    /**
     * @return array<int, NavigationItem>
     */
    public static function items(User $user): array
    {
        return app(Favorites::class)->favoriteVaults($user)
            ->values()
            ->map(fn (Vault $vault, int $index): NavigationItem => NavigationItem::make((string) $vault->name)
                ->icon(new HtmlString(view('filament.partials.vault-avatar', ['vault' => $vault, 'class' => 'size-full rounded'])->render()))
                ->url(VaultEntries::getUrl(['vault' => $vault->id]))
                ->isActiveWhen(fn (): bool => original_request()->routeIs('filament.app.pages.vault-entries')
                    && original_request()->query('vault') === $vault->id)
                ->sort($index + 2))
            ->all();
    }
}
