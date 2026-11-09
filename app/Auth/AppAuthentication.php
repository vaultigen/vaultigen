<?php

namespace App\Auth;

use App\Models\User;
use App\Services\MfaPolicy;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\Actions\DisableAppAuthenticationAction;
use Filament\Auth\MultiFactor\App\Actions\RegenerateAppAuthenticationRecoveryCodesAction;
use Filament\Auth\MultiFactor\App\Actions\SetUpAppAuthenticationAction;
use Filament\Facades\Filament;

class AppAuthentication extends \Filament\Auth\MultiFactor\App\AppAuthentication
{
    /** @return array<Action> */
    public function getActions(): array
    {
        $user = Filament::auth()->user();
        $policy = app(MfaPolicy::class);

        return [
            SetUpAppAuthenticationAction::make($this)
                ->hidden(fn (): bool => ! $policy->methodAllowed('app')
                    || ($user instanceof User && $policy->userHasMethod($user))),
            RegenerateAppAuthenticationRecoveryCodesAction::make($this)
                ->visible(fn (): bool => $this->isEnabled($user) && $this->isRecoverable() && $this->canRegenerateRecoveryCodes()),
            DisableAppAuthenticationAction::make($this)
                ->visible(fn (): bool => $user instanceof User && $policy->methodEnabled($user, 'app')),
        ];
    }
}
