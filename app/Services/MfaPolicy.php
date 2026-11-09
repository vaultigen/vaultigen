<?php

namespace App\Services;

use App\Auth\AppAuthentication;
use App\Auth\EmailAuthentication;
use App\Models\User;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Throwable;

/**
 * The single source of truth for multi-factor authentication questions.
 *
 * Both the panel configuration and the request-time enforcement consult this
 * service so that the allowed methods, the environment bypass and the accepted
 * assurances cannot drift apart.
 */
class MfaPolicy
{
    /**
     * Method identifiers accepted by the installation settings.
     *
     * @var list<string>
     */
    public const METHODS = ['app', 'email'];

    /**
     * Session assurances that satisfy the requirement for a factor.
     *
     * @var list<string>
     */
    public const ACCEPTED_ASSURANCES = ['strong', 'recovery', 'trusted_device'];

    public function __construct(private InstallationSettings $settings) {}

    /**
     * Whether multi-factor authentication is active at all.
     *
     * The environment flag is only ever honored outside production by the boot
     * guard in AppServiceProvider; here it simply reports the configured state.
     */
    public function enabled(): bool
    {
        return ! (bool) config('vaultigen.mfa_disabled');
    }

    /**
     * The methods the administrator allows, always at least one entry.
     *
     * This is deliberately strict: enforcement decisions must fail closed if
     * the policy cannot be read. Provider composition uses {@see providers()},
     * which tolerates an unreadable table while routes are registered.
     *
     * @return list<string>
     */
    public function methods(): array
    {
        $methods = array_values(array_intersect($this->settings->values()['mfa_methods'], self::METHODS));

        return $methods === [] ? ['app'] : $methods;
    }

    /**
     * Whether the installation allows the given method.
     */
    public function methodAllowed(string $method): bool
    {
        return in_array($method, $this->methods(), true);
    }

    /**
     * The methods the given user has actually enabled, restricted to the
     * allowed policy.
     *
     * @return list<string>
     */
    public function userMethods(User $user): array
    {
        $methods = [];

        foreach ($this->methods() as $method) {
            if ($this->methodEnabled($user, $method)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * Whether the user holds at least one allowed method.
     *
     * When MFA is disabled the requirement is vacuous, so every user qualifies.
     */
    public function userHasMethod(User $user): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        return $this->userMethods($user) !== [];
    }

    /**
     * The user's primary method, used for display and sensitive-action copy.
     */
    public function userMethod(User $user): ?string
    {
        return $this->userMethods($user)[0] ?? null;
    }

    /**
     * Whether a single method is enabled on the given user, regardless of the
     * installation policy.
     */
    public function methodEnabled(User $user, string $method): bool
    {
        return match ($method) {
            'app' => filled($user->getAttribute('app_authentication_secret')),
            'email' => $user->hasEmailAuthentication(),
            default => false,
        };
    }

    /**
     * The provider instances for the allowed methods, or an empty list when MFA
     * is disabled.
     *
     * Provider composition is evaluated while routes are registered, before a
     * fresh installation has run its migrations, so it falls back to every
     * method when the settings table cannot yet be read. Request-time
     * enforcement uses {@see methods()} and therefore never silently widens.
     *
     * @return array<int, MultiFactorAuthenticationProvider>
     */
    public function providers(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        try {
            $methods = $this->methods();
        } catch (Throwable) {
            $methods = self::METHODS;
        }

        return array_map(
            fn (string $method) => match ($method) {
                'email' => EmailAuthentication::make(),
                default => AppAuthentication::make()->recoverable(),
            },
            $methods,
        );
    }

    /**
     * Assurance values that allow access to vault and gated routes.
     *
     * @return list<string>
     */
    public function acceptedAssurances(): array
    {
        return self::ACCEPTED_ASSURANCES;
    }

    /**
     * Whether the session may proceed to gate-protected routes.
     *
     * A user without an enrolled method is allowed through so that they can be
     * forced into the set-up flow rather than logged out. Everyone else must
     * present a fresh second-factor assurance.
     */
    public function sessionSatisfied(User $user, ?string $assurance): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        if (! $this->userHasMethod($user)) {
            return true;
        }

        return in_array($assurance, $this->acceptedAssurances(), true);
    }

    /**
     * Number of days a trusted device may skip the challenge. Zero disables the
     * option entirely.
     */
    public function rememberDeviceDays(): int
    {
        try {
            return max(0, (int) $this->settings->values()['remember_device_days']);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Whether the trusted-device option is available at all.
     */
    public function trustedDevicesEnabled(): bool
    {
        return $this->enabled() && $this->rememberDeviceDays() > 0;
    }
}
