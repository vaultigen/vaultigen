<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Licensing\LicenseManager;
use App\Services\Licensing\LicenseState;
use App\Services\Licensing\LicenseStatus;
use App\Services\Licensing\SeatLimit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class License extends Page
{
    public const BUY_URL = 'https://vaultigen.com/pricing';

    public const MANAGE_URL = 'https://account.vaultigen.com';

    protected string $view = 'filament.pages.license';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?int $navigationSort = 15;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-installation');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('licensing.page.title');
    }

    public function getTitle(): string
    {
        return (string) __('licensing.page.title');
    }

    public function getSubheading(): string
    {
        return (string) __('licensing.page.subheading');
    }

    /**
     * The note at the bottom of the sidebar, shown to every signed-in user.
     * Clicking it opens a dialog that explains the license state.
     */
    public static function sidebarFooter(): View
    {
        $status = app(LicenseManager::class)->status();
        $licensee = $status->license?->licensee;
        $limit = LicenseManager::FREE_SEATS;
        $adminUrl = static::canAccess() ? static::getUrl() : null;

        $footer = match ($status->state) {
            LicenseState::Licensed => [
                'text' => __('licensing.footer.licensed', ['licensee' => $licensee]),
                'icon' => Heroicon::OutlinedCheckBadge, 'tone' => 'muted',
                'message' => __('licensing.footer.modal_licensed', ['licensee' => $licensee]),
                'links' => [],
            ],
            LicenseState::UpdatesExpired => [
                'text' => __('licensing.footer.updates_expired'),
                'icon' => Heroicon::OutlinedExclamationTriangle, 'tone' => 'warning',
                'message' => __('licensing.footer.modal_expired', ['licensee' => $licensee, 'limit' => $limit]),
                'links' => array_filter([
                    ['label' => __('licensing.footer.renew'), 'url' => self::MANAGE_URL, 'external' => true],
                    $adminUrl === null ? null : ['label' => __('licensing.footer.install_renewed'), 'url' => $adminUrl, 'external' => false],
                ]),
            ],
            default => [
                'text' => __('licensing.footer.free', ['limit' => $limit]),
                'icon' => Heroicon::OutlinedExclamationTriangle, 'tone' => 'unlicensed',
                'message' => __('licensing.footer.modal_free', ['limit' => $limit]),
                'links' => array_filter([
                    ['label' => __('licensing.footer.buy'), 'url' => self::BUY_URL, 'external' => true],
                    $adminUrl === null ? null : ['label' => __('licensing.footer.add'), 'url' => $adminUrl, 'external' => false],
                ]),
            ],
        };

        return view('filament.license-footer', $footer);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->installAction(), $this->removeAction()];
    }

    public function installAction(): Action
    {
        return Action::make('install')->label((string) __('licensing.page.install'))->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn (): bool => ! $this->status()->isManagedByEnvironment())
            ->modalHeading((string) __('licensing.page.install_heading'))
            ->modalDescription((string) __('licensing.page.install_description'))
            ->modalSubmitActionLabel((string) __('licensing.page.install_submit'))
            ->schema([
                Textarea::make('license')->label((string) __('licensing.page.license_field'))
                    ->required()
                    ->rows(10)
                    ->extraInputAttributes(['class' => 'font-mono', 'spellcheck' => 'false', 'autocomplete' => 'off']),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    $license = app(LicenseManager::class)->install($this->actor(), (string) $data['license']);
                    Notification::make()->success()->title((string) __('licensing.page.installed', ['licensee' => $license->licensee]))->send();
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title((string) __('licensing.page.install_failed'))
                        ->body(collect($exception->errors())->flatten()->first())->send();
                    $action->halt();
                }
            });
    }

    public function removeAction(): Action
    {
        return Action::make('remove')->label((string) __('licensing.page.remove'))->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (): bool => $this->status()->source === 'database')
            ->requiresConfirmation()
            ->modalDescription((string) __('licensing.page.remove_description', ['limit' => LicenseManager::FREE_SEATS]))
            ->action(function (): void {
                app(LicenseManager::class)->remove($this->actor());
                Notification::make()->success()->title((string) __('licensing.page.removed'))->send();
            });
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        Gate::authorize('manage-installation');
        $status = $this->status();

        return [
            'status' => $status,
            'license' => $status->license,
            'summary' => $this->summarize($status),
            'seats' => app(SeatLimit::class)->summary(),
            'buyUrl' => self::BUY_URL,
            'manageUrl' => self::MANAGE_URL,
        ];
    }

    /**
     * @return array{color: string, icon: Heroicon, heading: string, description: string}
     */
    private function summarize(LicenseStatus $status): array
    {
        $limit = ['limit' => LicenseManager::FREE_SEATS];
        $license = $status->license;

        return match (true) {
            $status->state === LicenseState::Licensed && $license !== null => [
                'color' => 'success', 'icon' => Heroicon::OutlinedCheckBadge,
                'heading' => (string) __('licensing.page.summary.licensed_heading', ['licensee' => $license->licensee]),
                'description' => (string) __('licensing.page.summary.licensed_description'),
            ],
            $status->state === LicenseState::UpdatesExpired && $license !== null => [
                'color' => 'warning', 'icon' => Heroicon::OutlinedExclamationTriangle,
                'heading' => (string) __('licensing.page.summary.expired_heading'),
                'description' => (string) __('licensing.page.summary.expired_description', $limit + [
                    'date' => (string) $license->updatesUntil, 'release' => (string) config('vaultigen.release_date'),
                ]),
            ],
            $status->state === LicenseState::Invalid => [
                'color' => 'danger', 'icon' => Heroicon::OutlinedXCircle,
                'heading' => (string) __('licensing.page.summary.invalid_heading'),
                'description' => (string) __('licensing.page.summary.invalid_description', $limit + [
                    'message' => (string) __('licensing.errors.'.$status->invalidReason),
                ]),
            ],
            default => [
                'color' => 'info', 'icon' => Heroicon::OutlinedInformationCircle,
                'heading' => (string) __('licensing.page.summary.free_heading'),
                'description' => (string) __('licensing.page.summary.free_description', $limit),
            ],
        };
    }

    private function status(): LicenseStatus
    {
        return app(LicenseManager::class)->status();
    }

    private function actor(): User
    {
        Gate::authorize('manage-installation');

        /** @var User */
        return auth()->user();
    }
}
