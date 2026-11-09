<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminRecovery
{
    public function __construct(private AuditWriter $audit, private InstallationSettings $settings) {}

    public function recover(string $email, #[\SensitiveParameter] string $temporaryPassword): void
    {
        $this->audit->transaction(function () use ($email, $temporaryPassword): void {
            $admins = User::query()->whereIn('id', DB::table('user_platform_roles')
                ->join('platform_roles', 'platform_roles.id', '=', 'user_platform_roles.platform_role_id')
                ->where('name', 'super_admin')->select('user_id'))->get();
            $user = $admins->sole(fn (User $user): bool => $user->normalized_email === Str::lower(trim($email)));
            if ($admins->count() !== 1) {
                throw ValidationException::withMessages(['email' => __('security.recovery_single_super_admin')]);
            }
            $user->forceFill([
                'password' => $temporaryPassword, 'must_reset_password' => true, 'is_active' => true,
                'app_authentication_secret' => null, 'app_authentication_recovery_codes' => null,
                'has_email_authentication' => false, 'remember_token' => null,
            ])->save();
            DB::table('trusted_devices')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            app(EmergencyAccess::class)->revokeFor($user->id, null);
            $sequence = $this->audit->record('auth.local_recovery', $user->id);
            foreach (array_unique([$user->email, ...$this->settings->values()['security_recipients']]) as $recipient) {
                DB::table('security_notification_deliveries')->insert([
                    'id' => (string) Str::uuid(), 'audit_sequence' => $sequence, 'recipient' => $recipient,
                ]);
            }
        });
    }
}
