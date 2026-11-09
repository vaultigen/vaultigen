<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\Operations\BackupPolicy;
use App\Services\Operations\BackupService;
use App\Services\Operations\BackupStorage;
use App\Services\Operations\MaintenanceWindow;
use App\Services\Operations\OperationLock;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class PruneBackups extends Command
{
    protected $signature = 'vaultigen:backups:prune';

    protected $description = 'Prune expired verified completed sets when retention is explicitly enabled';

    public function handle(BackupPolicy $policy, BackupService $backups, BackupStorage $storage): int
    {
        $lock = new OperationLock;
        $owner = (string) Str::uuid();
        $began = false;
        try {
            $policy->validate();
            if (! $policy->enabled('backup_retention_enabled')) {
                $this->info('Backup retention is disabled.');

                return self::SUCCESS;
            }
            $lock->acquire();
            $backups->assertNoConflict();
            app(MaintenanceWindow::class)->begin('backup_retention', $owner, 'Backup retention');
            $began = true;
            $preserved = false;
            $count = 0;
            foreach (BackupRun::query()->where('state', BackupRun::STATE_COMPLETED)->whereNotNull('path')->orderByDesc('completed_at')->get() as $run) {
                try {
                    $backups->verifySet((string) $run->path);
                } catch (Throwable) {
                    continue;
                }
                if (! $preserved) {
                    $preserved = true;

                    continue;
                }
                if ($run->completed_at === null || $run->completed_at->gte(now()->subDays((int) config('vaultigen.backup_retention_days')))) {
                    continue;
                }
                $storage->delete((string) $run->path);
                $run->forceFill(['path' => null, 'error_code' => 'pruned'])->save();
                $count++;
            }
            $this->info('Pruned '.$count.' local set(s). Off-host copying is managed separately.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Retention failed. Check configuration, active operations, and backup storage.');

            return self::FAILURE;
        } finally {
            if ($began) {
                app(MaintenanceWindow::class)->endOwned('backup_retention', $owner);
            }
            $lock->release();
        }
    }
}
