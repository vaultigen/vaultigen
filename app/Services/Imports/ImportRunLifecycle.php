<?php

namespace App\Services\Imports;

use App\Models\ImportRun;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Validation\ValidationException;

/**
 * Non-secret lifecycle transitions that live outside a running import.
 *
 * Cancelling is the only transition here. A run that has not started is
 * cancelled immediately, purging its staged source and wrapped credentials. A
 * run already executing in a worker gets a cancellation flag instead: the
 * runner stops at its next checkpoint and finalizes the run itself, so the
 * staging object is never pulled out from under an active import.
 */
final class ImportRunLifecycle
{
    public function __construct(
        private readonly ImportSourceStore $store,
        private readonly ImportCredentialStore $credentials,
        private readonly AuditWriter $audit,
    ) {}

    public function cancel(ImportRun $run, User $actor): void
    {
        if ($run->isTerminal()) {
            throw ValidationException::withMessages(['run' => __('imports.errors.already_finished')]);
        }

        if ($run->status === ImportRun::STATUS_IMPORTING) {
            $run->forceFill(['cancel_requested_at' => now(), 'updated_at' => now()])->save();
            $this->audit->record('import.cancel_requested', $actor->id, 'success', [
                'sanitized_context' => ['import_run_id' => (string) $run->id],
            ]);

            return;
        }

        $this->audit->transaction(function () use ($run, $actor): void {
            $this->store->purge($run);
            $this->credentials->purge($run);
            $run->forceFill([
                'status' => ImportRun::STATUS_CANCELLED,
                'updated_at' => now(),
            ])->save();

            $this->audit->record('import.cancelled', $actor->id, 'success', [
                'sanitized_context' => ['import_run_id' => (string) $run->id],
            ]);
        });
    }
}
