<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Services\Operations\BackupService;
use App\Services\Operations\DatabaseSnapshot;
use App\Services\Operations\MaintenanceWindow;
use App\Services\Operations\OperationFailedException;
use App\Services\Operations\OperationLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a backup outside the request that queued it.
 *
 * The payload carries only the run id. The service opens and closes the
 * maintenance window itself, and `run()` is idempotent. `$tries = 1` stops a
 * partially written set from being replayed on a redelivery; a worker that dies
 * mid-run can leave a running row and an open window. A timeout runs `failed()`
 * in the owning process, which re-enters the operation lock and cleans up;
 * otherwise recovery requires the lock, so a callback never clears a live owner.
 */
class RunBackup implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 1;

    public bool $failOnTimeout = true;

    /** @var int */
    public $timeout;

    public function __construct(public readonly string $backupRunId)
    {
        $this->timeout = max(300, (int) config('vaultigen.backup_job_timeout', 3600));
    }

    public function uniqueId(): string
    {
        return $this->backupRunId;
    }

    public function handle(BackupService $service): void
    {
        $run = BackupRun::query()->find($this->backupRunId);
        if ($run === null || $run->isTerminal()) {
            return;
        }

        $service->run($run);
    }

    public function failed(Throwable $exception): void
    {
        $lock = new OperationLock;
        try {
            $lock->acquire();
            $run = BackupRun::query()->find($this->backupRunId);
            if ($run !== null && ! $run->isTerminal()) {
                app(BackupService::class)->fail($run, 'queue_failed');
                app(MaintenanceWindow::class)->endOwned('backup', (string) $run->id);
                app(DatabaseSnapshot::class)->removeAbandonedTemporaries();
            }
        } catch (OperationFailedException) {
            // A surviving process still owns the operation; leave it offline.
        } finally {
            $lock->release();
        }
    }
}
