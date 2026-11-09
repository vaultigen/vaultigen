<?php

use App\Http\Middleware\BlockWritesDuringMaintenance;
use App\Http\Middleware\EnforceSessionLimits;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [SetLocale::class, BlockWritesDuringMaintenance::class, EnforceSessionLimits::class]);
        $middleware->redirectGuestsTo(fn (): string => route('filament.app.auth.login'));

        // The Host header is only restricted when the operator lists trusted
        // hosts; an empty list keeps the default behavior of accepting any host.
        // Hosts are regular expressions. Trusted proxies are read by the
        // framework's TrustProxies middleware from config/trustedproxy.php.
        $middleware->trustHosts(
            at: function (): array {
                $hosts = config('vaultigen.trusted_hosts');

                return is_string($hosts)
                    ? array_values(array_filter(array_map('trim', explode(',', $hosts)), fn (string $host): bool => $host !== ''))
                    : [];
            },
            subdomains: false,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['token', 'password', 'password_confirmation', 'currentPassword', 'confirmationCode', 'code', 'recoveryCode', 'data']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
