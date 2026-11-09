<?php

namespace App\Services\Licensing;

use App\Services\AuditWriter;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Enforces how many users may be able to sign in.
 *
 * Seats in use are active users plus open invitations. Every path that lets an
 * additional user sign in must call one of the ensure methods inside the
 * AuditWriter transaction that performs the change: its chain-head lock
 * serializes concurrent requests, so two admins cannot both take the last seat.
 * Existing users are never deactivated or restricted by this class.
 */
class SeatLimit
{
    public function __construct(private readonly LicenseManager $licenses, private readonly AuditWriter $audit) {}

    /**
     * The seat limit, or null when the license allows unlimited users.
     */
    public function limit(): ?int
    {
        return $this->licenses->seatLimit();
    }

    public function inUse(): int
    {
        return $this->activeUsers() + $this->openInvitations();
    }

    public function hasRoom(): bool
    {
        $limit = $this->limit();

        return $limit === null || $this->inUse() < $limit;
    }

    /**
     * A new invitation needs a seat. Open invitations for the same address are
     * replaced by it, so resending an invitation never needs an extra seat.
     */
    public function ensureCanInvite(string $normalizedEmail): void
    {
        $this->ensure('invitation', $this->activeUsers() + $this->openInvitations($normalizedEmail), 'email');
    }

    /**
     * Accepting consumes an invitation that already holds a seat, so only
     * active users count. This fails when the limit dropped after the invitation
     * was sent, for example because the license was removed.
     */
    public function ensureCanAccept(): void
    {
        $this->ensure('acceptance', $this->activeUsers(), 'email');
    }

    /**
     * Reactivating an account or creating an additional administrator needs a seat.
     */
    public function ensureCanActivate(string $context): void
    {
        $this->ensure($context, $this->inUse(), 'user');
    }

    /**
     * Run an operation and audit a seat-limit rejection after its transaction
     * has rolled back, so the audit event is not rolled back with it.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function auditingRejections(?string $actorId, Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (SeatLimitReached $exception) {
            $this->audit->record('seat_limit.reached', $actorId, 'denied', [
                'sanitized_context' => ['context' => $exception->context, 'in_use' => $exception->inUse, 'limit' => $exception->limit],
            ]);

            throw $exception;
        }
    }

    /**
     * The message shown to administrators when no seat is free.
     */
    public function message(): string
    {
        $key = match ($this->licenses->state()) {
            LicenseState::Licensed => 'licensing.seats.full_licensed',
            LicenseState::UpdatesExpired => 'licensing.seats.full_updates_expired',
            default => 'licensing.seats.full_free',
        };

        return (string) __($key, ['limit' => $this->limit()]);
    }

    /**
     * How many seats are in use, for administrators.
     */
    public function summary(): string
    {
        $limit = $this->limit();

        return $limit === null
            ? (string) __('licensing.seats.counter_unlimited', ['used' => $this->inUse()])
            : (string) __('licensing.seats.counter_limited', ['used' => $this->inUse(), 'limit' => $limit]);
    }

    private function ensure(string $context, int $inUse, string $field): void
    {
        $limit = $this->limit();
        if ($limit === null || $inUse < $limit) {
            return;
        }

        $message = $context === 'acceptance' ? (string) __('licensing.seats.acceptance_unavailable') : $this->message();

        throw SeatLimitReached::make($context, $inUse, $limit, $field, $message);
    }

    private function activeUsers(): int
    {
        return DB::table('users')->where('is_active', true)->count();
    }

    private function openInvitations(?string $excludingEmail = null): int
    {
        return DB::table('invitations')
            ->whereNull('consumed_at')->whereNull('cancelled_at')->where('expires_at', '>', now())
            ->when($excludingEmail !== null, fn ($query) => $query->where('intended_normalized_email', '!=', $excludingEmail))
            ->count();
    }
}
