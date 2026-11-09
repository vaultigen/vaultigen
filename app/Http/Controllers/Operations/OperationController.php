<?php

namespace App\Http\Controllers\Operations;

use App\Jobs\RunKeyRotation;
use App\Models\KeyRotationRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Resume a failed or interrupted key rotation from the Operations page.
 *
 * Retrying re-dispatches the same run, which is restartable, rather than
 * starting a fresh rotation with a new target key version. The route is guarded
 * by `manage-installation` and the request is a plain POST, so it does not add
 * a second authorization path.
 */
class OperationController
{
    public function resumeRotation(KeyRotationRun $run): RedirectResponse
    {
        Gate::authorize('manage-installation');

        if ($run->status === KeyRotationRun::STATUS_COMPLETED) {
            return back();
        }

        RunKeyRotation::dispatch((string) $run->id);

        return back()->with('status', (string) __('operations.notifications.rotation_resumed'));
    }
}
