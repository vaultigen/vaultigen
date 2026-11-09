<?php

namespace App\Console\Commands;

use App\Services\Operations\RestoreService;
use Illuminate\Console\Command;
use Throwable;

class VerifyBackup extends Command
{
    protected $signature = 'vaultigen:backup:verify {directory}';

    protected $description = 'Authenticate a backup and verify recovery inputs and every object without restoring';

    public function handle(RestoreService $restore): int
    {
        try {
            $manifest = $restore->preflight(rtrim((string) $this->argument('directory'), '/'));
        } catch (Throwable) {
            $this->error('Backup verification failed. Check the set, recovery inputs, and database engine.');

            return self::FAILURE;
        }
        $this->info('Verified backup '.$manifest['backup_run_id']);
        if ($manifest['format_version'] === 1) {
            $this->warn('Legacy set: preserve original recovery inputs; their values are not fingerprinted.');
        }

        return self::SUCCESS;
    }
}
