<?php

namespace App\Filament\Pages;

use App\Filament\Forms\Components\ConfirmationCodeInput;
use App\Models\User;
use App\Models\Vault;
use App\Services\EmergencyAccess as EmergencyAccessService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Time-limited, strongly re-authenticated emergency administrator access.
 *
 * @property-read Schema $form
 */
class EmergencyAccess extends Page
{
    protected string $view = 'filament.pages.emergency-access';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-installation');
    }

    public function getTitle(): string
    {
        return (string) __('emergency.title');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('emergency.navigation_label');
    }

    public function getSubheading(): string
    {
        return (string) __('emergency.subheading');
    }

    public function mount(): void
    {
        Gate::authorize('manage-installation');
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [$this->startAction(), $this->endAction()];
    }

    public function startAction(): Action
    {
        return Action::make('startEmergency')
            ->label((string) __('emergency.actions.start'))
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('danger')
            ->modalHeading((string) __('emergency.actions.start_modal_heading'))
            ->modalDescription((string) __('emergency.actions.start_modal_description'))
            ->modalSubmitActionLabel((string) __('emergency.actions.start'))
            ->schema([
                Select::make('vault_id')
                    ->label((string) __('emergency.fields.vault'))
                    ->options(fn (): array => $this->vaultOptions())
                    ->searchable()
                    ->required(),
                Textarea::make('reason')
                    ->label((string) __('emergency.fields.reason'))
                    ->required()
                    ->maxLength(1000)
                    ->helperText((string) __('emergency.fields.reason_helper')),
                TextInput::make('incident_reference')
                    ->label((string) __('emergency.fields.incident_reference'))
                    ->maxLength(255)
                    ->helperText((string) __('emergency.fields.incident_reference_helper')),
                TextInput::make('password')
                    ->label((string) __('emergency.fields.password'))
                    ->password()
                    ->required(),
                ConfirmationCodeInput::make('code'),
            ])
            ->action(function (array $data): void {
                try {
                    app(EmergencyAccessService::class)->start(
                        $this->actor(),
                        (string) $data['vault_id'],
                        (string) $data['reason'],
                        $data['incident_reference'] === null || $data['incident_reference'] === '' ? null : (string) $data['incident_reference'],
                        (string) $data['password'],
                        (string) $data['code'],
                    );
                    Notification::make()->success()->title((string) __('emergency.notifications.started'))->send();
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title((string) __('emergency.notifications.failed'))
                        ->body((string) collect($exception->errors())->flatten()->first())->send();
                }
            });
    }

    public function endAction(): Action
    {
        $active = $this->activeSession();

        return Action::make('endEmergency')
            ->label((string) __('emergency.actions.end'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->visible($active !== null)
            ->action(function () use ($active): void {
                app(EmergencyAccessService::class)->end($this->actor(), (string) $active->id);
                Notification::make()->success()->title((string) __('emergency.notifications.ended'))->send();
            });
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        Gate::authorize('manage-installation');

        $active = $this->activeSession();
        $vaultNames = Vault::query()->pluck('name', 'id')->all();
        $actorNames = User::query()->pluck('name', 'id')->all();

        $recent = DB::table('emergency_access_sessions')
            ->orderByDesc('started_at')
            ->limit(20)
            ->get()
            ->map(fn (\stdClass $session): array => [
                'vault' => $vaultNames[$session->vault_id] ?? (string) __('emergency.unknown_vault'),
                'actor' => $actorNames[$session->super_admin_user_id] ?? (string) __('emergency.unknown_actor'),
                'reason' => (string) $session->reason,
                'incident_reference' => $session->incident_reference,
                'started_at' => $session->started_at,
                'expires_at' => $session->expires_at,
                'closed_at' => $session->closed_at,
                'deliveries' => $this->deliveries((int) $session->notification_sequence),
            ])
            ->all();

        return [
            'active' => $active === null ? null : [
                'id' => (string) $active->id,
                'vault' => $vaultNames[$active->vault_id] ?? (string) __('emergency.unknown_vault'),
                'vault_url' => VaultEntries::getUrl(['vault' => (string) $active->vault_id]),
                'reason' => (string) $active->reason,
                'incident_reference' => $active->incident_reference,
                'started_at' => $active->started_at,
                'expires_at' => $active->expires_at,
                'deliveries' => $this->deliveries((int) $active->notification_sequence),
            ],
            'recent' => $recent,
        ];
    }

    /**
     * The durable notification deliveries for a session's audit event, with
     * their current retry state.
     *
     * @return list<array{recipient: string, status: string}>
     */
    private function deliveries(int $sequence): array
    {
        $rows = [];
        foreach (DB::table('security_notification_deliveries')->where('audit_sequence', $sequence)->orderBy('recipient')->get() as $delivery) {
            $status = $delivery->delivered_at !== null
                ? 'sent'
                : ($delivery->failure_code !== null ? 'failed' : 'pending');
            $rows[] = ['recipient' => (string) $delivery->recipient, 'status' => $status];
        }

        return $rows;
    }

    private function activeSession(): ?\stdClass
    {
        return app(EmergencyAccessService::class)->activeFor($this->actor());
    }

    /** @return array<string, string> */
    private function vaultOptions(): array
    {
        return Vault::query()->where('state', '!=', 'pending_purge')->orderBy('name')->pluck('name', 'id')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();
    }

    private function actor(): User
    {
        Gate::authorize('manage-installation');

        /** @var User */
        return auth()->user();
    }
}
