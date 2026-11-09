<?php

namespace App\Auth;

use App\Models\User;
use App\Services\TrustedDevices;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The login-page challenge that lets a valid trusted-device cookie skip the
 * second factor.
 *
 * The set-up-required middleware and page deliberately use the base
 * MultiFactorChallenge so that they still see the user's real enrolled
 * providers; only the sign-in page consults the cookie.
 */
class TrustedDeviceMultiFactorChallenge extends MultiFactorChallenge
{
    public function getFirstEnabledProvider(Authenticatable $user): ?MultiFactorAuthenticationProvider
    {
        $provider = parent::getFirstEnabledProvider($user);

        if ($provider === null || ! $user instanceof User) {
            return $provider;
        }

        if (app(TrustedDevices::class)->hasValidCookie($user)) {
            session()->put('vaultigen.trusted_device_used', true);

            return null;
        }

        return $provider;
    }
}
