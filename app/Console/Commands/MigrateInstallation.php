<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\Operations\BackupService;
use App\Services\Operations\BackupStorage;
use App\Services\Operations\InstallationState;
use App\Services\Operations\OperationFailedException;
use App\Services\Operations\OperationLock;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

class MigrateInstallation extends Command
{
    protected $signature = 'vaultigen:migrate {--wait=600 : Seconds to wait for another operation to finish}';

    protected $description = 'Gate application startup: validate keys and installation, back up, then apply pending migrations once';

    /**
     * Runs before web, worker, and scheduler start. Without pending migrations
     * it only validates and exits. With pending migrations it takes a verified
     * backup first and fails closed when that backup or the migration fails.
     * The backup service is resolved only after the key check, because it
     * needs the installation KEK. Database and migration errors propagate and
     * fail the gate with their own message.
     */
    public function handle(InstallationState $state): int
    {
        if (($missing = $state->missingKeys()) !== []) {
            $this->error('Installation keys are missing: '.implode(', ', $missing).'.');
            $this->line('For a new installation run: docker compose run --rm setup. For an existing one, restore the original keys; never generate new ones.');

            return self::FAILURE;
        }

        $bootstrappedId = $state->bootstrappedInstallationId();
        if ($bootstrappedId === null) {
            $this->error('This installation is not initialized. Run: docker compose run --rm setup');

            return self::FAILURE;
        }
        if ($bootstrappedId !== (string) config('vaultigen.installation_id')) {
            $this->error('The installation keys do not belong to this database. Restore the original key files into data/keys.');

            return self::FAILURE;
        }

        $lock = new OperationLock;
        try {
            if (! $this->acquire($lock)) {
                return self::FAILURE;
            }

            return $this->migrate($state, app(BackupService::class));
        } finally {
            $lock->release();
        }
    }

    private function migrate(InstallationState $state, BackupService $backups): int
    {
        $pending = $state->pendingMigrations();
        if ($pending === []) {
            $this->info('No pending migrations.');

            return self::SUCCESS;
        }

        $this->info(count($pending).' pending migration(s). Taking a verified backup first.');
        $run = $this->backUp($backups);
        if (is_string($run)) {
            $this->error('The pre-migration backup failed ('.$run.'). No migration was applied.');

            return self::FAILURE;
        }
        $this->line('Verified pre-migration backup: '.app(BackupStorage::class)->path((string) $run->path));

        return $this->call('migrate', ['--force' => true, '--no-interaction' => true]);
    }

    /**
     * The completed pre-migration backup, or the reason it failed or could not start.
     */
    private function backUp(BackupService $backups): BackupRun|string
    {
        try {
            $run = $backups->start(null, BackupRun::TRIGGER_MIGRATION);
            $backups->run($run);
        } catch (OperationFailedException $exception) {
            return $exception->failureCode;
        }

        return $run->refresh()->state === BackupRun::STATE_COMPLETED ? $run : (string) $run->error_code;
    }

    /**
     * Wait for a concurrent gate or operation; the winner applies migrations
     * and the waiter then finds none pending.
     */
    private function acquire(OperationLock $lock): bool
    {
        $deadline = now()->addSeconds(max(0, (int) $this->option('wait')));
        while (true) {
            try {
                $lock->acquire();

                return true;
            } catch (OperationFailedException $exception) {
                if ($exception->failureCode !== 'backup_in_progress' || now()->greaterThanOrEqualTo($deadline)) {
                    $this->error($exception->failureCode === 'restore_apply_failed'
                        ? 'A restore is incomplete. Keep services stopped and retry the restore.'
                        : 'Another operation holds the operations lock. Wait for it to finish and retry.');

                    return false;
                }
                Sleep::for(1)->second();
            }
        }
    }
}
