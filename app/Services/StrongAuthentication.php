<?php

namespace App\Services;

use App\Auth\EmailAuthentication;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class StrongAuthentication
{
    public function confirm(User $user, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code, string $errorKey = 'data.confirmationCode'): void
    {
        $key = 'strong-confirm:'.$user->id;
        $valid = ! RateLimiter::tooManyAttempts($key, 5);
        RateLimiter::hit($key, 60);
        $method = app(MfaPolicy::class)->userMethod($user);
        $codeValid = match ($method) {
            'email' => EmailAuthentication::make()->verifyCode($code, $user),
            'app' => AppAuthentication::make()->verifyCode($code, secret: $user->app_authentication_secret, shouldPreventCodeReuse: true),
            default => false,
        };
        if (! $valid || ! Hash::check($password, $user->password) || ! $codeValid) {
            app(AuditWriter::class)->record('auth.reauthentication', $user->id, 'denied');
            throw ValidationException::withMessages([$errorKey => $method === 'email'
                ? __('security.confirmation_email')
                : __('security.confirmation_app')]);
        }
        app(AuditWriter::class)->record('auth.reauthentication', $user->id);
        session()->put('vaultigen.strong_at', now()->timestamp);
        session()->put('vaultigen.method', $method === 'email' ? 'password_email' : 'password_totp');
        session()->put('vaultigen.assurance', 'strong');
    }

    /**
     * Send a fresh email code for sensitive-action confirmation when the user's
     * active method is the email code.
     */
    public function sendCode(User $user): bool
    {
        if (app(MfaPolicy::class)->userMethod($user) !== 'email') {
            return false;
        }

        return EmailAuthentication::make()->sendCode($user);
    }
}
