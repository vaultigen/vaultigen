<?php

namespace App\Providers\Filament;

use App\Auth\EditProfile;
use App\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\License;
use App\Filament\VaultigenTheme;
use App\Http\Middleware\EnforceSessionLimits;
use App\Http\Middleware\EnsureCryptographyAvailable;
use App\Http\Middleware\SetLocale;
use App\Services\InstallationSettings;
use App\Services\MfaPolicy;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->brandName(fn (): string => app(InstallationSettings::class)->values()['display_name'])
            ->path('')
            ->login(Login::class)
            ->passwordReset()
            ->profile(EditProfile::class)
            ->multiFactorAuthentication(
                fn (): array => app(MfaPolicy::class)->providers(),
                isRequired: fn (): bool => app(MfaPolicy::class)->enabled(),
            )
            ->viteTheme('resources/css/filament/app/theme.css')
            ->colors(VaultigenTheme::colors())
            ->defaultThemeMode(ThemeMode::Dark)
            ->font('ui-sans-serif', provider: LocalFontProvider::class)
            ->brandLogo(fn (): View => view('filament.brand-logo'))
            ->brandLogoHeight('1.75rem')
            ->favicon(asset('vaultigen-icon.svg'))
            ->sidebarCollapsibleOnDesktop()
            ->renderHook(PanelsRenderHook::BODY_START, fn (): View => view('filament.emergency-banner'))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn (): View => view('filament.auth.login-tagline'))
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn (): View => License::sidebarFooter())
            ->navigationGroups([
                'System' => NavigationGroup::make()->label(fn (): string => __('navigation.groups.system')),
                'Vaults' => NavigationGroup::make()->label(fn (): string => __('navigation.groups.vaults')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,

            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetLocale::class,
                EnforceSessionLimits::class,
                EnsureCryptographyAvailable::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
