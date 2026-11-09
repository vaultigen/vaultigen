<?php

namespace App\Models;

use App\Services\AuditWriter;
use App\Services\LocaleResolver;
use App\Services\MfaPolicy;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\Email\Concerns\InteractsWithEmailAuthentication;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $principal_id
 * @property string $normalized_email
 * @property bool $is_active
 * @property bool $must_reset_password
 * @property int $auth_version
 * @property string $name
 * @property string $email
 * @property string|null $locale
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $app_authentication_secret
 * @property array<string>|null $app_authentication_recovery_codes
 * @property bool $has_email_authentication
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'app_authentication_secret', 'app_authentication_recovery_codes', 'has_email_authentication', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasEmailAuthentication, HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, InteractsWithEmailAuthentication, Notifiable {
        InteractsWithEmailAuthentication::toggleEmailAuthentication as protected toggleEmailAuthenticationTrait;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->exists && $this->is_active && $panel->getId() === 'app';
    }

    /** @return BelongsToMany<Vault, $this> */
    public function favoriteVaults(): BelongsToMany
    {
        return $this->belongsToMany(Vault::class, 'favorite_vaults', 'user_id', 'vault_id');
    }

    /** @return BelongsToMany<Entry, $this> */
    public function favoriteEntries(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class, 'favorite_entries', 'user_id', 'entry_id');
    }

    /**
     * The locale queued mail and notifications should render in. Returning null
     * lets Laravel fall back to the application locale.
     */
    public function preferredLocale(): ?string
    {
        return app(LocaleResolver::class)->isSupported($this->locale) ? $this->locale : null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasPlatformRole('super_admin');
    }

    public function isAuditor(): bool
    {
        return $this->hasPlatformRole('auditor');
    }

    public function isVaultCreator(): bool
    {
        return $this->hasPlatformRole('vault_creator');
    }

    /**
     * The database holds the authoritative platform roles; the role name is not
     * trusted from the session or request.
     */
    public function hasPlatformRole(string $name): bool
    {
        return $this->is_active && DB::table('user_platform_roles')
            ->join('platform_roles', 'platform_roles.id', '=', 'user_platform_roles.platform_role_id')
            ->where('user_id', $this->id)->where('name', $name)->exists();
    }

    /** @return list<string> */
    public function platformRoleNames(): array
    {
        $names = DB::table('user_platform_roles')
            ->join('platform_roles', 'platform_roles.id', '=', 'user_platform_roles.platform_role_id')
            ->where('user_platform_roles.user_id', $this->id)
            ->orderBy('platform_roles.name')
            ->pluck('platform_roles.name')
            ->all();

        return array_values(array_map(fn (mixed $name): string => (string) $name, $names));
    }

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        $audit = app(AuditWriter::class);

        return $audit->transaction(function () use ($options, $audit): bool {
            $creating = ! $this->exists;
            if ($creating) {
                $this->principal_id = (string) Str::uuid();
                DB::table('principals')->insert(['id' => $this->principal_id, 'kind' => 'user', 'created_at' => now()]);
                $this->is_active = $this->is_active ?? true;
                $this->auth_version = 1;
                $this->must_reset_password = $this->must_reset_password ?? false;
            }
            $this->normalized_email = Str::lower(trim($this->email));
            $securityChanged = ! $creating && $this->isDirty(['password', 'app_authentication_secret', 'has_email_authentication', 'is_active']);
            if ($securityChanged) {
                $this->auth_version = (int) DB::table('users')->where('id', $this->id)->value('auth_version') + 1;
                $this->remember_token = null;
                DB::table('sessions')->where('user_id', $this->id)->delete();
                DB::table('trusted_devices')->where('user_id', $this->id)->delete();
            }
            $saved = parent::save($options);
            $audit->record($creating ? 'user.created' : 'user.updated', $this->id);

            return $saved;
        });
    }

    public function saveAppAuthenticationSecret(#[\SensitiveParameter] ?string $secret): void
    {
        abort_if($this->must_reset_password, 403, 'Change your temporary password before enrollment.');
        $wasEnabled = filled($this->app_authentication_secret);
        $this->app_authentication_secret = $secret;
        $this->save();
        if ($wasEnabled !== filled($secret)) {
            app(AuditWriter::class)->record($secret !== null ? 'auth.factor_added' : 'auth.factor_removed', $this->id);
        }
        if ($secret !== null) {
            session()->put('vaultigen.auth_version', $this->auth_version);
            session()->put('vaultigen.method', 'password_totp');
            session()->put('vaultigen.assurance', 'strong');
            session()->put('vaultigen.authenticated_at', now()->timestamp);
            session()->regenerate();
        }
    }

    public function toggleEmailAuthentication(bool $condition): void
    {
        $wasEnabled = $this->hasEmailAuthentication();
        $this->toggleEmailAuthenticationTrait($condition);
        if ($wasEnabled !== $condition) {
            app(AuditWriter::class)->record($condition ? 'auth.factor_added' : 'auth.factor_removed', $this->id);
        }
        if ($condition) {
            session()->put('vaultigen.auth_version', $this->auth_version);
            session()->put('vaultigen.method', 'password_email');
            session()->put('vaultigen.assurance', 'strong');
            session()->put('vaultigen.authenticated_at', now()->timestamp);
            session()->regenerate();
        }
    }

    /**
     * The raw factor methods present on the account, used for administration
     * display without consulting the installation policy.
     *
     * @return list<string>
     */
    public function mfaMethods(): array
    {
        $methods = [];

        if (filled($this->app_authentication_secret)) {
            $methods[] = 'app';
        }

        if ($this->hasEmailAuthentication()) {
            $methods[] = 'email';
        }

        return $methods;
    }

    public function mfaMethodLabel(): string
    {
        return match (true) {
            $this->mfaMethods() === [] => (string) __('users.mfa.none'),
            $this->mfaMethods() === ['app'] => (string) __('users.mfa.app'),
            $this->mfaMethods() === ['email'] => (string) __('users.mfa.email'),
            default => (string) __('users.mfa.app_email'),
        };
    }

    public function hasStrongMethod(): bool
    {
        return app(MfaPolicy::class)->userHasMethod($this);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_reset_password' => 'boolean',
            'has_email_authentication' => 'boolean',
            'auth_version' => 'integer',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
