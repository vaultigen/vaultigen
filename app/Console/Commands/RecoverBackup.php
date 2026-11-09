<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\Operations\BackupService;
use App\Services\Operations\DatabaseSnapshot;
use App\Services\Operations\MaintenanceWindow;
use App\Services\Operations\OperationLock;
use Illuminate\Console\Command;
use Throwable;

class RecoverBackup extends Command
{
    protected $signature = 'vaultigen:backup:recover {--services-stopped : Confirm all application processes have stopped}';

    protected $description = 'Fail interrupted backup runs after stopping services; never clear a live operation';

    public function handle(BackupService $backups, MaintenanceWindow $maintenance, DatabaseSnapshot $snapshot): int
    {
        if (! $this->option('services-stopped') || ! app()->isDownForMaintenance()) {
            $this->error('Put Laravel down and stop web, workers, and scheduler before recovery.');

            return self::FAILURE;
        }
        $lock = new OperationLock;
        try {
            $lock->acquire();
            foreach (BackupRun::query()->whereIn('state', [BackupRun::STATE_PENDING, BackupRun::STATE_RUNNING])->get() as $run) {
                $backups->fail($run, 'interrupted');
                $maintenance->endOwned('backup', (string) $run->id);
            }
            $snapshot->removeAbandonedTemporaries();
            $this->info('Interrupted backup runs failed and abandoned private staging was removed.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Recovery refused: an operation is alive or storage is unavailable.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
