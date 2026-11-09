<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\MfaPolicy;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a session without an enrolled factor away from the application's own
 * routes, which sit outside the panel's required-MFA middleware.
 */
class EnsureMfaEnrolled
{
    public function __construct(private MfaPolicy $mfa) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $this->mfa->userHasMethod($user)) {
            return $next($request);
        }

        abort_if($request->expectsJson(), 403);

        return redirect()->to((string) Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }
}
