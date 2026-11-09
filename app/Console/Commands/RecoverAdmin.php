<?php

namespace App\Console\Commands;

use App\Services\AdminRecovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class RecoverAdmin extends Command
{
    protected $signature = 'vaultigen:recover-admin {email}';

    protected $description = 'Recover the sole Super Admin locally, revoke sessions and require password reset and TOTP enrollment';

    public function handle(AdminRecovery $recovery): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run interactively to enter a temporary password securely.');

            return self::FAILURE;
        }
        $password = $this->secret('Temporary password');
        if (Validator::make(['password' => $password], ['password' => ['required', 'string', ...(! app()->isLocal() ? [Password::min(15)->uncompromised()] : [])]])->fails()) {
            $this->error(app()->isLocal() ? 'Enter a non-empty temporary password.' : 'Use an uncompromised temporary password of at least 15 characters.');

            return self::FAILURE;
        }
        $recovery->recover((string) $this->argument('email'), $password);
        $this->info('Sessions revoked. Sign in with the temporary password, choose a new password, and enroll an authenticator. Security notifications are durably pending; the scheduler delivers and retries them.');

        return self::SUCCESS;
    }
}
