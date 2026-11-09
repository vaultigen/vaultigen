<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Time-limited, strongly re-authenticated emergency administrator access.
 *
 * A session grants metadata view and individual secret reveal in exactly one
 * vault for at most 30 minutes. It is not a grant: it cannot be exported,
 * edited, deleted, moved, or shared, and it never satisfies a normal write
 * ability. Creation, closure, and expiry are prominent and notified durably.
 */
final class EmergencyAccess
{
    public const MAX_MINUTES = 30;

    public function __construct(
        private readonly AuditWriter $audit,
        private readonly InstallationSettings $settings,
        private readonly SecurityNotifications $notifications,
    ) {}

    public function start(User $actor, string $vaultId, string $reason, ?string $incidentReference, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code): string
    {
        Gate::forUser($actor)->authorize('manage-installation');
        $validated = Validator::make([
            'vault_id' => $vaultId,
            'reason' => trim($reason),
            'incident_reference' => $incidentReference === null ? null : trim($incidentReference),
        ], [
            'vault_id' => ['required', 'uuid', Rule::exists('vaults', 'id')->whereNot('state', 'pending_purge')],
            'reason' => ['required', 'string', 'max:1000'],
            'incident_reference' => ['nullable', 'string', 'max:255'],
        ])->validate();
        app(StrongAuthentication::class)->confirm($actor, $password, $code);

        $sessionId = (string) Str::uuid();
        $now = now();
        $expires = $now->addMinutes(self::MAX_MINUTES);
        $method = (string) session('vaultigen.method', 'password');

        [$sequence] = $this->audit->transaction(function () use ($actor, $validated, $sessionId, $now, $expires, $method): array {
            // A Super Admin holds at most one active emergency session.
            DB::table('emergency_access_sessions')
                ->where('super_admin_user_id', $actor->id)
                ->whereNull('closed_at')
                ->update(['closed_at' => $now, 'closed_by_user_id' => $actor->id, 'updated_at' => $now]);

            DB::table('emergency_access_sessions')->insert([
                'id' => $sessionId,
                'super_admin_user_id' => $actor->id,
                'vault_id' => $validated['vault_id'],
                'reason' => (string) $validated['reason'],
                'incident_reference' => $validated['incident_reference'] ?? null,
                'authentication_method' => $method,
                'authenticated_at' => $now,
                'started_at' => $now,
                'expires_at' => $expires,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $sequence = $this->audit->record('vault.emergency_started', $actor->id, 'success', [
                'vault_id' => (string) $validated['vault_id'],
                'target_type' => 'vault',
                'target_id' => (string) $validated['vault_id'],
                'sanitized_context' => [
                    'reason' => (string) $validated['reason'],
                    'incident_reference' => $validated['incident_reference'] ?? null,
                ],
            ]);

            DB::table('emergency_access_sessions')->where('id', $sessionId)->update(['notification_sequence' => $sequence]);

            return [$sequence];
        });

        $this->notifications->queue($sequence, $this->recipients($actor));

        return $sessionId;
    }

    public function end(User $actor, string $sessionId): void
    {
        Gate::forUser($actor)->authorize('manage-installation');

        $this->audit->transaction(function () use ($actor, $sessionId): void {
            $session = DB::table('emergency_access_sessions')->where('id', $sessionId)->first();
            abort_if($session === null, 404);
            if ($session->closed_at === null) {
                DB::table('emergency_access_sessions')->where('id', $sessionId)->update([
                    'closed_at' => now(),
                    'closed_by_user_id' => $actor->id,
                    'updated_at' => now(),
                ]);
                $this->audit->record('vault.emergency_ended', $actor->id, 'success', [
                    'vault_id' => (string) $session->vault_id,
                    'target_type' => 'vault',
                    'target_id' => (string) $session->vault_id,
                    'sanitized_context' => ['reason' => 'closed'],
                ]);
            }
        });
    }

    /**
     * Close a user's open sessions because their authority was withdrawn, so
     * restoring it later cannot revive them. Runs inside the caller's audited
     * transaction.
     */
    public function revokeFor(string $userId, ?string $actorId): void
    {
        $this->closeOpenSessions('super_admin_user_id', $userId, $actorId);
    }

    /**
     * Close a vault's open sessions because it is being purged, so restoring it
     * later cannot revive them. Runs inside the caller's audited transaction.
     */
    public function revokeForVault(string $vaultId, ?string $actorId): void
    {
        $this->closeOpenSessions('vault_id', $vaultId, $actorId);
    }

    private function closeOpenSessions(string $column, string $value, ?string $actorId): void
    {
        $sessions = DB::table('emergency_access_sessions')
            ->where($column, $value)
            ->whereNull('closed_at')
            ->get();

        foreach ($sessions as $session) {
            DB::table('emergency_access_sessions')->where('id', $session->id)->update([
                'closed_at' => now(),
                'closed_by_user_id' => $actorId,
                'updated_at' => now(),
            ]);
            $this->audit->record('vault.emergency_ended', $actorId, 'success', [
                'vault_id' => (string) $session->vault_id,
                'target_type' => 'vault',
                'target_id' => (string) $session->vault_id,
                'sanitized_context' => ['reason' => 'revoked'],
            ]);
        }
    }

    /**
     * Close sessions whose expiry has passed. Expiry is enforced on every
     * request; this only records the end and settles the UI state.
     */
    public function expireDue(): int
    {
        $due = DB::table('emergency_access_sessions')
            ->whereNull('closed_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($due as $session) {
            $this->audit->transaction(function () use ($session): void {
                DB::table('emergency_access_sessions')->where('id', $session->id)->whereNull('closed_at')->update([
                    'closed_at' => $session->expires_at,
                    'updated_at' => now(),
                ]);
                $this->audit->record('vault.emergency_ended', null, 'success', [
                    'vault_id' => (string) $session->vault_id,
                    'target_type' => 'vault',
                    'target_id' => (string) $session->vault_id,
                    'source_channel' => 'scheduler',
                    'sanitized_context' => ['reason' => 'expired'],
                ]);
            });
        }

        return $due->count();
    }

    public function activeFor(User $user): ?\stdClass
    {
        return DB::table('emergency_access_sessions')
            ->where('super_admin_user_id', $user->id)
            ->whereNull('closed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * Other active Super Admins, or the configured security recipients when the
     * acting administrator is the sole one.
     *
     * @return list<string>
     */
    private function recipients(User $actor): array
    {
        $otherAdmins = User::query()
            ->where('is_active', true)
            ->whereKeyNot($actor->id)
            ->whereIn('id', DB::table('user_platform_roles')
                ->join('platform_roles', 'platform_roles.id', '=', 'user_platform_roles.platform_role_id')
                ->where('platform_roles.name', 'super_admin')
                ->select('user_platform_roles.user_id'))
            ->pluck('email')
            ->all();

        if ($otherAdmins !== []) {
            return array_values(array_map(fn (mixed $email): string => (string) $email, $otherAdmins));
        }

        return $this->settings->values()['security_recipients'];
    }
}
