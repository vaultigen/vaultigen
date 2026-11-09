<?php

namespace App\Console\Commands;

use App\Models\KeyRotationRun;
use App\Services\Operations\KeyRotationService;
use App\Services\Operations\OperationFailedException;
use Illuminate\Console\Command;

class RotateKek extends Command
{
    protected $signature = 'vaultigen:rotate-kek';

    protected $description = 'Rewrap every vault key under the active installation KEK';

    public function handle(KeyRotationService $service): int
    {
        try {
            $run = $service->startInstallationRewrap(null);
        } catch (OperationFailedException $exception) {
            $this->error('Installation-key rewrap not started: '.$this->message($exception->failureCode));

            return self::FAILURE;
        }

        $this->info("Rewrapping vault keys from [{$run->source_key_id}] to [{$run->target_key_id}].");

        $service->run($run);
        $run->refresh();

        if ($run->status !== KeyRotationRun::STATUS_COMPLETED) {
            $this->error('Installation-key rewrap failed: '.$this->message((string) $run->error_code));

            return self::FAILURE;
        }

        $this->info(sprintf('Installation-key rewrap completed: %d vault keys rewrapped. Old keys stay in the keyring for retained backups.', $run->processed_count));

        return self::SUCCESS;
    }

    private function message(string $code): string
    {
        $key = 'operations.errors.'.$code;

        return trans()->has($key) ? (string) __($key) : (string) __('operations.errors.generic');
    }
}
