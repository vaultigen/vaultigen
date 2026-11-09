<?php

namespace App\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailAuthenticationCode;
use App\Services\MfaPolicy;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Email\Actions\DisableEmailAuthenticationAction;
use Filament\Auth\MultiFactor\Email\Actions\SetUpEmailAuthenticationAction;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use SensitiveParameter;

class EmailAuthentication extends \Filament\Auth\MultiFactor\Email\EmailAuthentication
{
    public function __construct()
    {
        $this->codeNotification(VerifyEmailAuthenticationCode::class);
    }

    /**
     * Throttle verification attempts against an already-sent code.
     *
     * Filament throttles only how often a code is sent. Email codes are the sole
     * second factor for some accounts, so cap how many guesses a caller can make
     * against one code before it must be re-issued.
     */
    public function verifyCode(#[SensitiveParameter] string $code, ?HasEmailAuthentication $user = null): bool
    {
        $user ??= Filament::auth()->user();

        if (! $user instanceof Model) {
            return parent::verifyCode($code, $user);
        }

        $key = 'email-authentication-verify:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            return false;
        }

        RateLimiter::hit($key, decaySeconds: 60);

        if (! parent::verifyCode($code, $user)) {
            return false;
        }

        RateLimiter::clear($key);

        return true;
    }

    /** @return array<Action> */
    public function getActions(): array
    {
        $user = Filament::auth()->user();
        $policy = app(MfaPolicy::class);

        return [
            SetUpEmailAuthenticationAction::make($this)
                ->hidden(fn (): bool => ! $policy->methodAllowed('email')
                    || ($user instanceof User && $policy->userHasMethod($user))),
            DisableEmailAuthenticationAction::make($this)
                ->visible(fn (): bool => $user instanceof User && $policy->methodEnabled($user, 'email')),
        ];
    }
}
