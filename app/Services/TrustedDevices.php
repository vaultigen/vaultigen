<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues, validates and revokes "remember this device" cookies.
 *
 * Only a hash of the secret half of the cookie is stored. The selector is the
 * public lookup id. Cookies are encrypted by Laravel's EncryptCookies middleware
 * and are re-issued with a rotated secret on every use.
 */
class TrustedDevices
{
    public const COOKIE = 'vaultigen_trusted_device';

    public function __construct(private AuditWriter $audit, private MfaPolicy $mfa) {}

    /**
     * Whether the request carries a valid trusted-device cookie for the user.
     */
    public function hasValidCookie(User $user): bool
    {
        return $this->matchingRow($user) !== null;
    }

    /**
     * Create a trusted device after a full password plus factor sign-in.
     */
    public function remember(User $user): void
    {
        $days = $this->mfa->rememberDeviceDays();

        if ($days <= 0) {
            return;
        }

        $selector = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));

        DB::table('trusted_devices')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'selector' => $selector,
            'token_hash' => $this->hash($token),
            'user_agent' => $this->userAgent(),
            'ip_address' => request()->ip(),
            'created_at' => now(),
            'expires_at' => now()->addDays($days),
        ]);

        $this->queueCookie($selector, $token, $days * 24 * 60);
        $this->audit->record('auth.trusted_device_created', $user->id);
    }

    /**
     * Rotate the secret and extend the record after the cookie skipped a
     * challenge. Its absolute expiry is never extended.
     */
    public function consume(User $user): void
    {
        $row = $this->matchingRow($user);

        if ($row === null) {
            return;
        }

        $token = bin2hex(random_bytes(32));

        DB::table('trusted_devices')->where('id', $row->id)->update([
            'token_hash' => $this->hash($token),
            'last_used_at' => now(),
        ]);

        $expiresAt = Carbon::parse((string) $row->expires_at);
        $minutes = max(0, (int) ceil(($expiresAt->getTimestamp() - now()->getTimestamp()) / 60));

        if ($minutes > 0) {
            $this->queueCookie((string) $row->selector, $token, $minutes);
        }

        $this->audit->record('auth.trusted_device_used', $user->id);
    }

    public function revoke(User $user, string $id): void
    {
        $row = DB::table('trusted_devices')->where('id', $id)->where('user_id', $user->id)->first();

        abort_if($row === null, 404);

        DB::table('trusted_devices')->where('id', $id)->delete();

        if ($this->currentSelector() === $row->selector) {
            $this->forgetCookie();
        }

        $this->audit->record('auth.trusted_device_revoked', $user->id);
    }

    public function revokeAll(User $user): void
    {
        DB::table('trusted_devices')->where('user_id', $user->id)->delete();
        $this->forgetCookie();
        $this->audit->record('auth.trusted_device_revoked', $user->id);
    }

    public function revokeAllExceptCurrent(User $user): void
    {
        $selector = $this->currentSelector();

        $query = DB::table('trusted_devices')->where('user_id', $user->id);

        if ($selector !== null) {
            $query->where('selector', '!=', $selector);
        }

        $deleted = $query->delete();

        if ($deleted > 0) {
            $this->audit->record('auth.trusted_device_revoked', $user->id);
        }
    }

    /**
     * The selector of the device making the current request, if any.
     */
    public function currentSelector(): ?string
    {
        $value = request()->cookie(self::COOKIE);

        if (! is_string($value) || ! str_contains($value, '.')) {
            return null;
        }

        [$selector] = explode('.', $value, 2);

        return $selector !== '' ? $selector : null;
    }

    /**
     * The current policy always applies: nothing matches while the feature is
     * off, and a shortened period also shortens already remembered devices.
     */
    private function matchingRow(User $user): ?\stdClass
    {
        if (! $this->mfa->trustedDevicesEnabled()) {
            return null;
        }

        $value = request()->cookie(self::COOKIE);

        if (! is_string($value) || ! str_contains($value, '.')) {
            return null;
        }

        [$selector, $token] = explode('.', $value, 2);

        if ($selector === '' || $token === '') {
            return null;
        }

        $row = DB::table('trusted_devices')
            ->where('selector', $selector)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where('created_at', '>', now()->subDays($this->mfa->rememberDeviceDays()))
            ->first();

        if ($row === null || ! hash_equals((string) $row->token_hash, $this->hash($token))) {
            return null;
        }

        return $row;
    }

    private function queueCookie(string $selector, string $token, int $minutes): void
    {
        Cookie::queue(
            self::COOKIE,
            $selector.'.'.$token,
            $minutes,
            config('session.path', '/'),
            config('session.domain'),
            app()->isProduction(),
            true,
            false,
            'lax',
        );
    }

    private function forgetCookie(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE, config('session.path', '/'), config('session.domain')));
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function userAgent(): ?string
    {
        $userAgent = request()->userAgent();

        return is_string($userAgent) && $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null;
    }
}
