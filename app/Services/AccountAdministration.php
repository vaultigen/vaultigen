<?php

namespace App\Services;

use App\Models\User;
use App\Services\Licensing\SeatLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountAdministration
{
    public function __construct(private AuditWriter $audit, private SeatLimit $seats) {}

    public function activate(User $actor, string $id, bool $active): void
    {
        Gate::forUser($actor)->authorize('manage-installation');
        $this->seats->auditingRejections($actor->id, fn () => $this->audit->transaction(function () use ($actor, $id, $active): void {
            $user = User::findOrFail($id);
            abort_if(! $active && $actor->id === $id, 422, (string) __('users.administration.cannot_deactivate_self'));
            if ($active && ! $user->is_active) {
                $this->seats->ensureCanActivate('reactivation');
            }
            $user->forceFill(['is_active' => $active])->save();
            if (! $active) {
                app(EmergencyAccess::class)->revokeFor($id, $actor->id);
            }
            $this->audit->record($active ? 'account.activated' : 'account.deactivated', $actor->id);
        }));
    }

    /** @param array<string> $roles */
    public function roles(User $actor, string $id, array $roles): void
    {
        Gate::forUser($actor)->authorize('manage-installation');
        Validator::make(['roles' => $roles], ['roles' => ['array'], 'roles.*' => ['string', Rule::in(['super_admin', 'auditor', 'vault_creator']), 'distinct']])->validate();
        $this->audit->transaction(function () use ($actor, $id, $roles): void {
            User::findOrFail($id);
            abort_if($actor->id === $id && ! in_array('super_admin', $roles), 422, (string) __('users.administration.cannot_remove_own_admin'));
            DB::table('user_platform_roles')->where('user_id', $id)->delete();
            foreach ($roles as $role) {
                DB::table('user_platform_roles')->insert(['user_id' => $id, 'platform_role_id' => DB::table('platform_roles')->where('name', $role)->value('id')]);
            }
            if (! in_array('super_admin', $roles, true)) {
                app(EmergencyAccess::class)->revokeFor($id, $actor->id);
            }
            $this->audit->record('account.roles_changed', $actor->id);
        });
    }

    public function resetFactors(User $actor, string $id, string $reason, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code): void
    {
        Gate::forUser($actor)->authorize('manage-installation');
        Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'max:1000']])->validate();
        abort_if($actor->id === $id, 422, (string) __('users.administration.use_account_security'));
        app(StrongAuthentication::class)->confirm($actor, $password, $code);
        $this->audit->transaction(function () use ($actor, $id, $reason): void {
            $user = User::findOrFail($id);
            $user->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null, 'has_email_authentication' => false, 'remember_token' => null])->save();
            DB::table('users')->where('id', $id)->increment('auth_version');
            DB::table('sessions')->where('user_id', $id)->delete();
            DB::table('trusted_devices')->where('user_id', $id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            app(EmergencyAccess::class)->revokeFor($id, $actor->id);
            $sequence = $this->audit->record('auth.admin_factor_reset', $actor->id);
            DB::table('account_factor_resets')->insert(['id' => (string) Str::uuid(), 'user_id' => $id, 'actor_id' => $actor->id, 'reason' => trim($reason), 'audit_sequence' => $sequence, 'created_at' => now()]);
            foreach (array_unique([$user->email, ...app(InstallationSettings::class)->values()['security_recipients']]) as $recipient) {
                DB::table('security_notification_deliveries')->insert(['id' => (string) Str::uuid(), 'audit_sequence' => $sequence, 'recipient' => $recipient]);
            }
        });
    }
}
