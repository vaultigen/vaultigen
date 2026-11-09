<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Favorites;

class Dashboard extends \Filament\Pages\Dashboard
{
    protected string $view = 'filament.pages.dashboard';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $favorites = app(Favorites::class);

        return [
            'favoriteVaults' => $favorites->favoriteVaults($user),
            'favoriteEntries' => $favorites->favoriteEntries($user),
        ];
    }
}
