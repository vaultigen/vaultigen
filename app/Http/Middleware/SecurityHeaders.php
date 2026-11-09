<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Site icons and vault images are inert, versioned images that set their
        // own stricter policy; letting the browser cache them avoids one request per row.
        if (! $request->routeIs('entries.icon', 'vaults.image')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=()');

        $maxAge = (int) config('vaultigen.hsts.max_age', 0);
        if ($request->isSecure() && $maxAge > 0) {
            $value = 'max-age='.$maxAge;
            $value .= config('vaultigen.hsts.include_subdomains') === true ? '; includeSubDomains' : '';
            $value .= config('vaultigen.hsts.preload') === true ? '; preload' : '';
            $response->headers->set('Strict-Transport-Security', $value);
        }

        return $response;
    }
}
