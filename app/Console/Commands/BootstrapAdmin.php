<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Licensing\SeatLimit;
use App\Services\Licensing\SeatLimitReached;
use App\Services\PersonalVaults;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class BootstrapAdmin extends Command
{
    protected $signature = 'vaultigen:bootstrap {email} {--name=Administrator}';

    protected $description = 'Create a Super Admin; password is read from a hidden prompt';

    public function handle(AuditWriter $audit, SeatLimit $seats): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run interactively to enter a password securely.');

            return self::FAILURE;
        }
        $data = ['email' => Str::lower(trim((string) $this->argument('email'))), 'name' => $this->option('name'), 'password' => $this->secret('Administrator password')];
        $validator = Validator::make($data, [
            'email' => ['required', 'email:rfc', 'max:254', 'unique:users,normalized_email'], 'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', ...(! app()->isLocal() ? [Password::min(15)->uncompromised()] : [])],
        ]);
        if ($validator->fails()) {
            $this->error(app()->isLocal() ? 'Use a valid unused email, name, and a non-empty password.' : 'Use a valid unused email, name, and an uncompromised password of at least 15 characters.');

            return self::FAILURE;
        }
        try {
            $seats->auditingRejections(null, fn () => $audit->transaction(function () use ($data, $audit, $seats): void {
                $isFirstAdmin = DB::table('installation_settings')->where('id', 1)->whereNull('bootstrapped_at')->exists();
                if (! $isFirstAdmin) {
                    $seats->ensureCanActivate('administrator');
                }
                $user = User::create($data);
                $user->forceFill(['email_verified_at' => now()])->save();
                DB::table('user_platform_roles')->insert([
                    'user_id' => $user->id,
                    'platform_role_id' => DB::table('platform_roles')->where('name', 'super_admin')->value('id'),
                ]);
                if ($isFirstAdmin) {
                    DB::table('installation_settings')->where('id', 1)->update([
                        'bootstrapped_at' => now(), 'installation_id' => config('vaultigen.installation_id'),
                    ]);
                }
                $audit->record($isFirstAdmin ? 'installation.bootstrapped' : 'user.administrator_created', $user->id);
                app(PersonalVaults::class)->provisionFor($user);
            }));
        } catch (SeatLimitReached) {
            $this->error($seats->message());

            return self::FAILURE;
        }
        $this->info(app()->isLocal() ? 'Administrator created. Sign in with the password you supplied.' : 'Administrator created. Sign in and enroll an authenticator; save the offline recovery codes.');

        return self::SUCCESS;
    }
}
