<?php

namespace App\Http\Middleware;

use App\Contracts\KekProvider;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fails fast when required cryptography is unavailable.
 */
class EnsureCryptographyAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->assertAvailable();

        return $next($request);
    }

    protected function assertAvailable(): void
    {
        if (! $this->sodiumLoaded()) {
            abort(503, 'Vaultigen requires the PHP sodium extension.');
        }

        try {
            $activeKeyId = app(KekProvider::class)->activeKeyId();

            if ($activeKeyId === '') {
                throw new RuntimeException('No active installation key.');
            }
        } catch (RuntimeException) {
            abort(503, 'Vaultigen cannot resolve its installation encryption keys. Restore the protected keyring before continuing.');
        }
    }

    protected function sodiumLoaded(): bool
    {
        return extension_loaded('sodium');
    }
}
