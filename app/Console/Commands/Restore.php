<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationFailedException;
use App\Services\Operations\RestoreService;
use Illuminate\Console\Command;

class Restore extends Command
{
    protected $signature = 'vaultigen:restore {directory : The backup set directory} {--services-stopped : Confirm web, workers, and scheduler have been stopped} {--force : Skip the interactive confirmation}';

    protected $description = 'Restore a backup set into this installation after verifying every recovery input';

    public function handle(RestoreService $service): int
    {
        $directory = rtrim((string) $this->argument('directory'), '/');

        try {
            $manifest = $service->preflight($directory);
        } catch (OperationFailedException $exception) {
            $this->error('Restore preflight failed: '.$this->message($exception->failureCode));
            $this->line('No changes were made to the target installation.');

            return self::FAILURE;
        }

        if (($manifest['format_version'] ?? 1) === 1) {
            $this->warn('Legacy set: recovery secret values cannot all be checked before application. Use the original inputs and complete a restore drill.');
        }

        $required = is_array($manifest['required_key_ids'] ?? null) ? $manifest['required_key_ids'] : [];
        $this->table(
            [(string) __('operations.restore.table_header')],
            [
                [(string) __('operations.restore.run', ['id' => (string) ($manifest['backup_run_id'] ?? '')])],
                [(string) __('operations.restore.created', ['at' => (string) ($manifest['created_at'] ?? '')])],
                [(string) __('operations.restore.driver', ['driver' => (string) ($manifest['database_driver'] ?? '')])],
                [(string) __('operations.restore.objects', ['count' => (string) ($manifest['object_count'] ?? 0)])],
                [(string) __('operations.restore.kek_keys', ['ids' => implode(', ', is_array($required['kek'] ?? null) ? $required['kek'] : [])])],
                [(string) __('operations.restore.audit_keys', ['ids' => implode(', ', is_array($required['audit'] ?? null) ? $required['audit'] : [])])],
            ],
        );

        if (! $this->option('force') && ! $this->confirm((string) __('operations.restore.confirm'))) {
            $this->warn('Restore cancelled.');

            return self::FAILURE;
        }

        try {
            $service->restore($directory, (bool) $this->option('services-stopped'));
        } catch (OperationFailedException $exception) {
            $this->error('Restore failed: '.$this->message($exception->failureCode));
            $this->line('Keep the installation offline. If application began, storage/framework/restore-in-progress.json marks the partial restore. Retry with the same set and original keys or restart on a clean target.');

            return self::FAILURE;
        }

        $this->info('Restore completed. Sign in and verify recovered data before returning the installation to service.');

        return self::SUCCESS;
    }

    private function message(string $code): string
    {
        $key = 'operations.errors.'.$code;

        return trans()->has($key) ? (string) __($key) : (string) __('operations.errors.generic');
    }
}
