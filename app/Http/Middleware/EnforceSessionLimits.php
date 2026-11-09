<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\InstallationSettings;
use App\Services\MfaPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceSessionLimits
{
    public function __construct(private InstallationSettings $settings, private MfaPolicy $mfa) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            $user->refresh();
            $limits = $this->settings->values();
            $now = now()->timestamp;
            $session = $request->session();
            $started = $session->get('vaultigen.started_at', $now);
            $last = $session->get('vaultigen.last_at', $now);
            $version = $session->get('vaultigen.auth_version', $user->auth_version);
            if (! $user->is_active || $version !== $user->auth_version
                || ! $this->mfa->sessionSatisfied($user, $session->get('vaultigen.assurance'))
                || $now - $started >= $limits['absolute_minutes'] * 60
                || $now - $last >= $limits['inactivity_minutes'] * 60) {
                Auth::logout();
                $session->invalidate();
                $session->regenerateToken();

                return redirect()->route('filament.app.auth.login');
            }
            $session->put('vaultigen.started_at', $started);
            $session->put('vaultigen.last_at', $now);
            $session->put('vaultigen.auth_version', $version);
            if ($user->must_reset_password && ! $request->routeIs('filament.app.auth.profile', 'filament.app.auth.logout')
                && ! $request->is('livewire/*')) {
                return redirect()->route('filament.app.auth.profile');
            }
        }

        return $next($request);
    }
}
