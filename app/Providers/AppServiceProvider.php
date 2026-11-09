<?php

namespace App\Providers;

use App\Contracts\KekProvider;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Crypto\KeyringKekProvider;
use App\Services\Imports\ImportRegistry;
use App\Services\Imports\Keepass\KeepassXmlDriver;
use App\Services\Imports\Syspass\SyspassXmlDriver;
use App\Services\InstallationSettings;
use App\Services\Licensing\LicenseManager;
use App\Services\MfaPolicy;
use App\Services\Operations\OperationLock;
use App\Services\VaultCreationPolicy;
use Carbon\CarbonImmutable;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View as ViewInstance;
use Livewire\Livewire;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->guardAgainstMfaBypass();

        $this->app->bind(KekProvider::class, function (): KekProvider {
            return config('vaultigen.kek_provider') === 'environment'
                ? KeyringKekProvider::fromJson((string) config('vaultigen.kek_keyring'))
                : KeyringKekProvider::fromFile((string) config('vaultigen.kek_file'));
        });

        $this->app->singleton(ImportRegistry::class);
        $this->app->scoped(LicenseManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if ($event->command === 'up' && is_file(OperationLock::restoreMarker())) {
                throw new RuntimeException('Restore application is incomplete. Keep services stopped and retry the full restore before bringing the installation up.');
            }
        });
        View::composer('mail.*', function (ViewInstance $view): void {
            $view->with('brandName', app(InstallationSettings::class)->displayName());
        });
        Livewire::addLocation(classNamespace: 'App\\Filament\\Livewire');
        Livewire::addPersistentMiddleware([EnsureMultiFactorAuthenticationIsEnabled::class]);
        app(ImportRegistry::class)->register(SyspassXmlDriver::class);
        app(ImportRegistry::class)->register(KeepassXmlDriver::class);
        Gate::define('manage-installation', fn (User $user): bool => $user->isSuperAdmin() && ! $user->must_reset_password && app(MfaPolicy::class)->userHasMethod($user));
        Gate::define('view-activity', fn (User $user): bool => ($user->isSuperAdmin() || $user->isAuditor()) && ! $user->must_reset_password && app(MfaPolicy::class)->userHasMethod($user));
        Gate::define('create-vault', fn (User $user): bool => app(VaultCreationPolicy::class)->allows($user) && ! $user->must_reset_password && app(MfaPolicy::class)->userHasMethod($user));
        Event::listen(Login::class, function (Login $event): void {
            app(AuditWriter::class)->record('auth.login', (string) $event->user->getAuthIdentifier());
            session()->put('vaultigen.started_at', now()->timestamp);
            session()->put('vaultigen.last_at', now()->timestamp);
            session()->put('vaultigen.auth_version', $event->user->getAttribute('auth_version'));
            session()->put('vaultigen.method', 'password_setup');
            session()->put('vaultigen.assurance', 'setup');
            session()->put('vaultigen.authenticated_at', now()->timestamp);
        });
        Event::listen(Logout::class, function (Logout $event): void {
            app(AuditWriter::class)->record('auth.logout', $event->user->getAuthIdentifier());
        });
        Event::listen(Failed::class, function (Failed $event): void {
            app(AuditWriter::class)->record('auth.login', $event->user?->getAuthIdentifier(), 'denied');
        });
    }

    /**
     * The MFA bypass is a development-only escape hatch. Refuse to start rather
     * than silently run without a second factor anywhere else.
     */
    protected function guardAgainstMfaBypass(): void
    {
        if (config('vaultigen.mfa_disabled') && ! $this->app->environment(['local', 'testing'])) {
            throw new RuntimeException('VAULTIGEN_MFA_DISABLED may only be enabled in the local or testing environment.');
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => match (true) {
            app()->isLocal() => Password::min(1),
            app()->environment('testing') => null,
            default => Password::min(15)->uncompromised(),
        });
    }
}
