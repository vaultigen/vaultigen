<?php

namespace App\Http\Middleware;

use App\Services\Operations\MaintenanceWindow;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pauses mutating HTTP requests while a backup holds the maintenance window.
 *
 * Safe methods keep working, so an administrator can still read the Operations
 * page while the snapshot is taken. A blocked write gets a 503 with a
 * `Retry-After` hint instead of a confusing validation error.
 */
class BlockWritesDuringMaintenance
{
    public function __construct(private readonly MaintenanceWindow $window) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || ! $this->window->active()) {
            return $next($request);
        }

        abort(503, (string) __('operations.maintenance.write_blocked'), ['Retry-After' => '30']);
    }
}
