<?php

namespace App\Console\Commands;

use App\Models\KeyRotationRun;
use App\Services\Operations\KeyRotationService;
use Illuminate\Console\Command;

class ResumeRotation extends Command
{
    protected $signature = 'vaultigen:rotation:resume {run : The rotation run UUID}';

    protected $description = 'Resume an interrupted or failed key rotation';

    public function handle(KeyRotationService $service): int
    {
        $run = KeyRotationRun::query()->find((string) $this->argument('run'));
        if ($run === null) {
            $this->error((string) __('operations.errors.rotation_not_found'));

            return self::FAILURE;
        }

        if ($run->status === KeyRotationRun::STATUS_COMPLETED) {
            $this->info('That rotation already completed.');

            return self::SUCCESS;
        }

        $service->run($run);
        $run->refresh();

        $this->info(sprintf('Rotation %s: %d processed, %d failures.', (string) $run->status, $run->processed_count, $run->failed_count));

        return $run->status === KeyRotationRun::STATUS_COMPLETED ? self::SUCCESS : self::FAILURE;
    }
}
