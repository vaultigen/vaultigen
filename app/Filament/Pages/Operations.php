<?php

namespace App\Filament\Pages;

use App\Filament\Forms\Components\ConfirmationCodeInput;
use App\Jobs\RunBackup;
use App\Jobs\RunKeyRotation;
use App\Models\BackupRun;
use App\Models\KeyRotationRun;
use App\Models\User;
use App\Models\Vault;
use App\Services\Operations\BackupService;
use App\Services\Operations\KeyRotationService;
use App\Services\Operations\OperationFailedException;
use App\Services\Operations\OperationsStatus;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Backup readiness, key health, and the triggers for a backup or a rotation.
 *
 * Everything shown here is non-secret status. Starting a backup opens the
 * maintenance window; starting a rotation additionally asks for fresh strong
 * reauthentication. The page is split into a Backup tab and a Keys tab, and it
 * reloads itself while an operation is active so it does not need a Livewire
 * request during a maintenance window.
 */
class Operations extends Page
{
    protected string $view = 'filament.pages.operations';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?int $navigationSort = 25;

    /** @var array<string, mixed>|null */
    private ?array $reportCache = null;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-installation');
    }

    public function getTitle(): string
    {
        return (string) __('operations.title');
    }

    public function getSubheading(): string
    {
        return (string) __('operations.subheading');
    }

    public function mount(): void
    {
        Gate::authorize('manage-installation');
    }

    public function content(Schema $schema): Schema
    {
        $report = $this->report();
        $active = $this->activeOperation();

        return $schema->components([
            Tabs::make('operations')
                ->id('operations-tabs')
                ->persistTab()
                ->tabs([
                    Tab::make((string) __('operations.tabs.backup'))
                        ->icon(Heroicon::OutlinedCircleStack)
                        ->schema(array_values(array_filter([
                            $this->html('filament.pages.partials.operations.readiness', ['checks' => $report['readiness']]),
                            ($report['maintenance'] !== null || $active !== null)
                                ? $this->html('filament.pages.partials.operations.maintenance', ['maintenance' => $report['maintenance'], 'active' => $active])
                                : null,
                            Actions::make([$this->runBackupAction()]),
                            $this->html('filament.pages.partials.operations.latest-backup', ['backup' => $report['latest_backup'], 'health' => $report['backup_health']]),
                        ]))),
                    Tab::make((string) __('operations.tabs.keys'))
                        ->icon(Heroicon::OutlinedKey)
                        ->schema([
                            $this->html('filament.pages.partials.operations.key-health', ['health' => $report['key_health']]),
                            $this->html('filament.pages.partials.operations.recovery-inputs', ['inputs' => $report['recovery_inputs']]),
                            Actions::make([$this->rotateKekAction(), $this->rotateVaultKeyAction()]),
                            $this->html('filament.pages.partials.operations.rotations', ['rotations' => $report['rotations'], 'errors' => $this->translatedErrors()]),
                        ]),
                ]),
        ]);
    }

    public function runBackupAction(): Action
    {
        return Action::make('runBackup')
            ->label((string) __('operations.actions.backup'))
            ->icon(Heroicon::OutlinedCircleStack)
            ->modalHeading((string) __('operations.actions.backup'))
            ->modalDescription((string) __('operations.backup.confirm'))
            ->modalSubmitActionLabel((string) __('operations.actions.backup_now'))
            ->disabled(fn (): bool => ! $this->backupReady())
            ->tooltip(fn (): ?string => $this->backupReady() ? null : (string) __('operations.actions.disabled_not_ready'))
            ->action(function (): void {
                Gate::authorize('manage-installation');

                try {
                    $run = app(BackupService::class)->start($this->actor(), BackupRun::TRIGGER_MANUAL);
                } catch (OperationFailedException $exception) {
                    $this->notifyFailure('operations.notifications.backup_not_started', $exception->failureCode);

                    return;
                }

                RunBackup::dispatch((string) $run->id);
                Notification::make()->success()->title((string) __('operations.notifications.backup_started'))->send();
            });
    }

    public function rotateKekAction(): Action
    {
        return Action::make('rotateKek')
            ->label((string) __('operations.actions.rotate_kek'))
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->modalHeading((string) __('operations.actions.rotate_kek'))
            ->modalDescription((string) __('operations.rotation.kek_description'))
            ->modalSubmitActionLabel((string) __('operations.actions.rotate_kek'))
            ->disabled(fn (): bool => ! $this->canStartOperation())
            ->tooltip(fn (): ?string => $this->canStartOperation() ? null : (string) __('operations.actions.disabled_busy'))
            ->schema([
                TextInput::make('password')->label((string) __('operations.fields.password'))->password()->required(),
                ConfirmationCodeInput::make('code'),
            ])
            ->action(function (array $data): void {
                Gate::authorize('manage-installation');

                $this->launchRotation(fn (): KeyRotationRun => app(KeyRotationService::class)->startInstallationRewrap($this->actor(), (string) $data['password'], (string) $data['code']));
            });
    }

    public function rotateVaultKeyAction(): Action
    {
        return Action::make('rotateVaultKey')
            ->label((string) __('operations.actions.rotate_vault'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('warning')
            ->modalHeading((string) __('operations.actions.rotate_vault'))
            ->modalDescription((string) __('operations.rotation.vault_description'))
            ->modalSubmitActionLabel((string) __('operations.actions.rotate_vault'))
            ->disabled(fn (): bool => ! $this->canStartOperation())
            ->tooltip(fn (): ?string => $this->canStartOperation() ? null : (string) __('operations.actions.disabled_busy'))
            ->schema([
                Select::make('vault_id')
                    ->label((string) __('operations.fields.vault'))
                    ->options(fn (): array => Vault::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                TextInput::make('password')->label((string) __('operations.fields.password'))->password()->required(),
                ConfirmationCodeInput::make('code'),
            ])
            ->action(function (array $data): void {
                Gate::authorize('manage-installation');

                $this->launchRotation(fn (): KeyRotationRun => app(KeyRotationService::class)->startVaultRotation($this->actor(), (string) $data['vault_id'], (string) $data['password'], (string) $data['code']));
            });
    }

    /**
     * Start a rotation, dispatch its job, and translate a domain failure into a
     * notification. Shared by both rotation actions so the failure handling is
     * defined once.
     *
     * @param  Closure(): KeyRotationRun  $starter
     */
    private function launchRotation(Closure $starter): void
    {
        try {
            $run = $starter();
        } catch (OperationFailedException $exception) {
            $this->notifyFailure('operations.notifications.rotation_not_started', $exception->failureCode);

            return;
        }

        RunKeyRotation::dispatch((string) $run->id);
        Notification::make()->success()->title((string) __('operations.notifications.rotation_started'))->send();
    }

    /**
     * @return array{active: array<string, mixed>|null, maintenance: array<string, mixed>|null}
     */
    protected function getViewData(): array
    {
        Gate::authorize('manage-installation');

        return [
            'active' => $this->activeOperation(),
            'maintenance' => $this->report()['maintenance'],
        ];
    }

    /**
     * @return array{kind: string, run_id: string, progress: string|null, started_at: string|null}|null
     */
    private function activeOperation(): ?array
    {
        $backup = BackupRun::query()->whereIn('state', [BackupRun::STATE_PENDING, BackupRun::STATE_RUNNING])->orderByDesc('created_at')->first();
        if ($backup !== null) {
            return ['kind' => 'backup', 'run_id' => (string) $backup->id, 'progress' => null, 'started_at' => $backup->started_at?->toDateTimeString()];
        }

        $rotation = KeyRotationRun::query()->whereIn('status', [KeyRotationRun::STATUS_PENDING, KeyRotationRun::STATUS_RUNNING])->orderByDesc('created_at')->first();
        if ($rotation !== null) {
            return [
                'kind' => 'rotation',
                'run_id' => (string) $rotation->id,
                'progress' => (string) __('operations.rotation.progress', ['processed' => $rotation->processed_count, 'failed' => $rotation->failed_count]),
                'started_at' => $rotation->started_at?->toDateTimeString(),
            ];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function translatedErrors(): array
    {
        $errors = [];
        foreach (KeyRotationRun::query()->whereNotNull('error_code')->distinct()->pluck('error_code') as $code) {
            if (is_string($code)) {
                $key = 'operations.errors.'.$code;
                $errors[$code] = trans()->has($key) ? (string) __($key) : (string) __('operations.errors.generic');
            }
        }

        return $errors;
    }

    /**
     * Every readiness check must be healthy before a backup can be trusted, so
     * the button stays disabled until then.
     */
    private function backupReady(): bool
    {
        foreach ($this->report()['readiness'] as $check) {
            if ($check['state'] !== 'healthy') {
                return false;
            }
        }

        return true;
    }

    private function canStartOperation(): bool
    {
        return Gate::allows('manage-installation')
            && $this->report()['maintenance'] === null
            && $this->activeOperation() === null;
    }

    /**
     * @return array<string, mixed>
     */
    private function report(): array
    {
        return $this->reportCache ??= app(OperationsStatus::class)->report();
    }

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    private function html(string $view, array $data): Html
    {
        return Html::make(fn (): string => view($view, $data)->render());
    }

    private function notifyFailure(string $titleKey, string $code): void
    {
        $key = 'operations.errors.'.$code;
        Notification::make()->danger()->title((string) __($titleKey))
            ->body(trans()->has($key) ? (string) __($key) : (string) __('operations.errors.generic'))
            ->send();
    }

    private function actor(): User
    {
        Gate::authorize('manage-installation');

        /** @var User */
        return auth()->user();
    }
}
