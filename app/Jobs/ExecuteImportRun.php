<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Services\AuditWriter;
use App\Services\Imports\ImportCredentialStore;
use App\Services\Imports\ImportRunner;
use App\Services\Operations\MaintenanceActiveException;
use App\Services\Operations\MaintenanceWindow;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs an import outside the request that queued it.
 *
 * The job payload carries only the run id; the driver's execution credentials
 * (for example the sysPass master password, or nothing at all) live in the
 * run's KEK-wrapped credential envelope and are unwrapped here, then purged in
 * a `finally`. A run without an envelope is an error. The run is claimed atomically (`queued` -> `importing`) so a
 * duplicate delivery or a cancelled run is a no-op, and `$tries = 1` prevents
 * the domain writes from being replayed. A worker that dies mid-run leaves a
 * stale `importing` run that the cleanup command fails and purges.
 */
class ExecuteImportRun implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 1;

    /** @var int */
    public $timeout;

    public function __construct(public readonly string $runId)
    {
        $this->timeout = max(60, (int) config('vaultigen.import_job_timeout', 3600));
    }

    public function uniqueId(): string
    {
        return $this->runId;
    }

    public function handle(ImportRunner $runner, ImportCredentialStore $credentials, MaintenanceWindow $maintenance): void
    {
        $run = ImportRun::query()->find($this->runId);
        if ($run === null || $run->isTerminal()) {
            return;
        }

        // Writing during a backup would leave a set that can never be restored.
        if (($maintenance->current()['operation'] ?? null) === 'backup') {
            throw new MaintenanceActiveException('backup');
        }

        $claimed = ImportRun::query()
            ->whereKey($run->id)
            ->where('status', ImportRun::STATUS_QUEUED)
            ->update([
                'status' => ImportRun::STATUS_IMPORTING,
                'started_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        try {
            $plain = $credentials->open($run->refresh());
            $runner->run($run, $plain);
        } finally {
            $credentials->purge($run->refresh());
        }
    }

    public function failed(Throwable $exception): void
    {
        $run = ImportRun::query()->find($this->runId);
        if ($run === null || $run->isTerminal()) {
            return;
        }

        $run->forceFill([
            'status' => ImportRun::STATUS_FAILED,
            'error_code' => 'queue_failed',
            'failed_at' => now(),
            'updated_at' => now(),
        ])->save();

        app(ImportCredentialStore::class)->purge($run);
        app(AuditWriter::class)->record('import.failed', (string) $run->requested_by_user_id, 'failed', [
            'sanitized_context' => ['import_run_id' => (string) $run->id, 'error_code' => 'queue_failed'],
        ]);
    }
}
