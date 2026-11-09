<?php

namespace App\Filament\Livewire;

use App\Filament\Pages\EmergencyAccess as EmergencyAccessPage;
use App\Models\User;
use App\Models\Vault;
use App\Services\EmergencyAccess;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The persistent banner shown to an administrator who holds an active
 * emergency session, with a direct control to end it.
 */
class EmergencyBanner extends Component
{
    public function end(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $active = app(EmergencyAccess::class)->activeFor($user);

        if ($active === null) {
            return;
        }

        app(EmergencyAccess::class)->end($user, (string) $active->id);

        Notification::make()->success()->title((string) __('emergency.notifications.ended'))->send();

        $this->redirect(EmergencyAccessPage::getUrl(), navigate: true);
    }

    public function render(): View
    {
        $user = auth()->user();
        $active = $user instanceof User ? app(EmergencyAccess::class)->activeFor($user) : null;

        return view('filament.livewire.emergency-banner', [
            'vault' => $active === null
                ? null
                : (string) (Vault::query()->whereKey($active->vault_id)->value('name') ?? __('emergency.unknown_vault')),
            'expiresAt' => $active === null ? null : (string) $active->expires_at,
        ]);
    }
}
