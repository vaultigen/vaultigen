<?php

namespace App\Console\Commands;

use App\Services\Operations\InstallationState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

class SetupInstallation extends Command
{
    protected $signature = 'vaultigen:setup {--email= : The first administrator email address} {--name=Administrator : The first administrator name}';

    protected $description = 'Prepare a container installation: create missing keys, migrate, and create the first administrator';

    /**
     * Setup is resumable. It creates only missing recovery inputs on an empty
     * database, never replaces an existing key, and creates an administrator
     * only while the installation has not been bootstrapped. Migrations and the
     * administrator prompt run in fresh processes so they read the new keys.
     */
    public function handle(InstallationState $state): int
    {
        if (($problem = $state->databasePasswordProblem()) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $migrated = $state->databaseMigrated();
        $bootstrappedId = $state->bootstrappedInstallationId();

        $missing = $state->missingKeys();
        if ($migrated && $missing !== []) {
            $this->error('This database already contains an installation, but these recovery inputs are missing: '.implode(', ', $missing).'.');
            $this->line('Restore the original key files into data/keys. Setup never generates replacement keys for an existing installation.');

            return self::FAILURE;
        }
        if ($bootstrappedId !== null && $bootstrappedId !== (string) config('vaultigen.installation_id')) {
            $this->error('The installation keys do not belong to this database. Restore the original key files into data/keys.');

            return self::FAILURE;
        }
        if ($missing !== [] && ! $this->createKeys($missing)) {
            return self::FAILURE;
        }

        if (! $this->runStep(['php', 'artisan', 'migrate', '--force', '--no-interaction'])) {
            $this->error('Migrations failed. Fix the reported problem and run setup again; existing keys are kept.');

            return self::FAILURE;
        }

        if ($state->bootstrappedInstallationId() === null) {
            if (! $this->input->isInteractive()) {
                $this->error('Create the first administrator interactively: docker compose run --rm setup');

                return self::FAILURE;
            }
            $email = (string) ($this->option('email') ?: $this->ask('Administrator email'));
            $name = (string) ($this->option('name') ?: 'Administrator');
            if (! $this->runStep(['php', 'artisan', 'vaultigen:bootstrap', $email, '--name='.$name], interactive: true)) {
                $this->error('The administrator was not created. Run setup again; existing keys and data are kept.');

                return self::FAILURE;
            }
        } else {
            $this->info('An administrator already exists; administrator creation is skipped.');
        }

        $this->info('Setup completed.');
        $this->line('Back up the key files in data/keys away from this server before storing real data.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $missing
     */
    private function createKeys(array $missing): bool
    {
        if (in_array('app_key', $missing, true) && ! $this->createApplicationKey()) {
            return false;
        }
        $commands = in_array('installation', $missing, true) ? ['vaultigen:initialize' => []] : [];
        $commands += ['vaultigen:kek:generate' => ['--if-missing' => true], 'vaultigen:backup-key:generate' => ['--if-missing' => true]];
        foreach ($commands as $command => $options) {
            if ($this->callSilently($command, $options) !== self::SUCCESS) {
                $this->error("{$command} failed. Setup never replaces an existing key file; restore an incomplete one from your key backup.");

                return false;
            }
        }
        $this->info('Created the missing installation keys: '.implode(', ', $missing).'.');

        return true;
    }

    /**
     * Create the key file exclusively with owner-only permissions; an existing
     * (empty or unreadable) file is never overwritten.
     */
    private function createApplicationKey(): bool
    {
        $path = (string) config('vaultigen.app_key_file');
        $mask = umask(0077);
        $file = $path === '' ? false : @fopen($path, 'x');
        umask($mask);
        if ($file === false || fwrite($file, 'base64:'.base64_encode(random_bytes(32))) === false) {
            $this->error('The application key file ('.($path ?: 'VAULTIGEN_APP_KEY_FILE is not set').') could not be created. Setup never replaces an existing key file; restore an empty or unreadable one from your key backup.');

            return false;
        }

        return fclose($file);
    }

    /**
     * @param  list<string>  $command
     */
    private function runStep(array $command, bool $interactive = false): bool
    {
        $process = Process::path(base_path())->forever();
        if ($interactive) {
            // A terminal lets the child hide the password prompt; piped input (-T) is passed through.
            $process = SymfonyProcess::isTtySupported() ? $process->tty() : $process->input(STDIN);
        }

        return $process->run($command, fn (string $type, string $output) => $this->output->write($output))->successful();
    }
}
