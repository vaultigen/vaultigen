<?php

namespace App\Console\Commands;

use App\Models\KeyRotationRun;
use App\Models\Vault;
use App\Services\Operations\KeyRotationService;
use App\Services\Operations\OperationFailedException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class RotateVaultKey extends Command
{
    protected $signature = 'vaultigen:rotate-vault-key {vault : The vault UUID or slug}';

    protected $description = 'Re-encrypt one vault under a new vault key version';

    public function handle(KeyRotationService $service): int
    {
        $vault = $this->resolveVault((string) $this->argument('vault'));
        if ($vault === null) {
            $this->error((string) __('operations.errors.vault_not_found'));

            return self::FAILURE;
        }

        try {
            $run = $service->startVaultRotation(null, (string) $vault->id);
        } catch (OperationFailedException $exception) {
            $this->error('Vault-key rotation not started: '.$this->message($exception->failureCode));

            return self::FAILURE;
        }

        $this->info("Rotating vault [{$vault->name}] to a new key version.");

        $service->run($run);
        $run->refresh();

        if ($run->status !== KeyRotationRun::STATUS_COMPLETED) {
            $this->error('Vault-key rotation failed: '.$this->message((string) $run->error_code));
            $this->line('The run can be resumed with vaultigen:rotation:resume '.$run->id.'.');

            return self::FAILURE;
        }

        $this->info(sprintf('Vault-key rotation completed: %d records re-encrypted, %d failures.', $run->processed_count, $run->failed_count));

        return self::SUCCESS;
    }

    private function resolveVault(string $identifier): ?Vault
    {
        return Vault::query()
            ->where('slug', $identifier)
            ->when(Str::isUuid($identifier), fn (Builder $query): Builder => $query->orWhere('id', $identifier))
            ->first();
    }

    private function message(string $code): string
    {
        $key = 'operations.errors.'.$code;

        return trans()->has($key) ? (string) __($key) : (string) __('operations.errors.generic');
    }
}
