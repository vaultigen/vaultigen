<?php

namespace App\Console\Commands;

use App\Jobs\RunBackup;
use App\Models\BackupRun;
use App\Services\AuditWriter;
use App\Services\Operations\BackupPolicy;
use App\Services\Operations\BackupService;
use App\Services\Operations\BackupStorage;
use App\Services\Operations\OperationFailedException;
use Illuminate\Console\Command;

class Backup extends Command
{
    protected $signature = 'vaultigen:backup {--scheduled : Queue a scheduled backup without blocking the scheduler}';

    protected $description = 'Create a verified data-backup set encrypted with the backup key';

    public function handle(BackupService $service): int
    {
        $scheduled = (bool) $this->option('scheduled');
        try {
            if ($scheduled) {
                $policy = app(BackupPolicy::class);
                $policy->validate();
                if (! $policy->enabled('backup_schedule_enabled')) {
                    return self::SUCCESS;
                }
                $policy->assertQueue();
            }
            $run = $service->start(null, $scheduled ? BackupRun::TRIGGER_SCHEDULED : BackupRun::TRIGGER_COMMAND);
        } catch (OperationFailedException $exception) {
            if ($scheduled) {
                BackupRun::query()->create([
                    'state' => BackupRun::STATE_FAILED, 'trigger' => BackupRun::TRIGGER_SCHEDULED,
                    'error_code' => $exception->failureCode, 'failed_at' => now(),
                ]);
                app(AuditWriter::class)->record('backup.failed', null, 'failed', [
                    'sanitized_context' => ['error_code' => $exception->failureCode, 'trigger' => 'scheduled'],
                ]);
            }
            $this->error($this->message($exception->failureCode));

            return self::FAILURE;
        }

        $this->info("Backup run {$run->id} started. The installation is quiesced for the snapshot.");

        if ($scheduled) {
            try {
                RunBackup::dispatch((string) $run->id);
            } catch (\Throwable) {
                $service->fail($run, 'queue_failed');

                return self::FAILURE;
            }
            $this->info('Backup queued: '.$run->id);

            return self::SUCCESS;
        }
        $service->run($run);

        $run->refresh();
        if ($run->state !== BackupRun::STATE_COMPLETED) {
            $this->error('Backup failed: '.$this->message((string) $run->error_code));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Backup completed: %d objects, %d bytes, driver %s, schema %s.',
            $run->object_count,
            $run->total_bytes,
            (string) $run->database_driver,
            (string) $run->schema_version,
        ));
        $this->line('Verified backup directory: '.app(BackupStorage::class)->path((string) $run->path));
        $this->line('Copy the set off-host to offline or write-protected storage and keep the backup keyring separate.');

        return self::SUCCESS;
    }

    private function message(string $code): string
    {
        $key = 'operations.errors.'.$code;

        return trans()->has($key) ? (string) __($key) : (string) __('operations.errors.generic');
    }
}
