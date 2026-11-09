<?php

namespace App\Filament\Pages;

use App\Filament\Forms\Components\ConfirmationCodeInput;
use App\Filament\Livewire\RecentInvitations;
use App\Models\Invitation;
use App\Models\User;
use App\Services\AccountAdministration;
use App\Services\Invitations;
use App\Services\Licensing\SeatLimit;
use App\Services\Licensing\SeatLimitReached;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class Users extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.users';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-installation');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) User::query()->count();
    }

    public function getSubheading(): string
    {
        return (string) __('users.subheading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(User::query())
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable()->copyable(),
                TextColumn::make('platform_roles')->label((string) __('users.columns.platform_roles'))
                    ->badge()
                    ->state(fn (User $record): array => $record->platformRoleNames())
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title())
                    ->colors(['danger' => 'super_admin', 'info' => 'auditor', 'warning' => 'vault_creator'])
                    ->placeholder((string) __('users.placeholder.ordinary_user'))
                    ->toggleable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereExists(
                        DB::table('user_platform_roles')
                            ->join('platform_roles', 'platform_roles.id', '=', 'user_platform_roles.platform_role_id')
                            ->whereColumn('user_platform_roles.user_id', 'users.id')
                            ->where('platform_roles.name', 'like', "%{$search}%"),
                    )),
                IconColumn::make('is_active')->label((string) __('users.columns.active'))->boolean()->sortable(),
                IconColumn::make('email_verified_at')->label((string) __('users.columns.verified'))->boolean()
                    ->state(fn (User $record): bool => $record->email_verified_at !== null)
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('mfa_method')->label((string) __('users.columns.mfa'))
                    ->badge()
                    ->state(fn (User $record): string => $record->mfaMethodLabel())
                    ->color(fn (string $state): string => match ($state) {
                        (string) __('users.mfa.app') => 'success',
                        (string) __('users.mfa.email') => 'info',
                        (string) __('users.mfa.app_email') => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(),
                TextColumn::make('created_at')->label((string) __('users.columns.registered'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label((string) __('users.filters.active')),
                TernaryFilter::make('email_verified_at')->label((string) __('users.filters.email_verified'))->nullable(),
                SelectFilter::make('mfa_method')->label((string) __('users.filters.mfa_method'))
                    ->options([
                        'app' => (string) __('users.filters.mfa_options.app'),
                        'email' => (string) __('users.filters.mfa_options.email'),
                        'none' => (string) __('users.filters.mfa_options.none'),
                    ])
                    ->query(function (Builder $query, array $state): Builder {
                        return match ($state['value'] ?? null) {
                            'app' => $query->whereNotNull('app_authentication_secret'),
                            'email' => $query->where('has_email_authentication', true),
                            'none' => $query->whereNull('app_authentication_secret')->where('has_email_authentication', false),
                            default => $query,
                        };
                    }),
                SelectFilter::make('platform_role')->label((string) __('users.filters.platform_role'))
                    ->options([
                        'super_admin' => (string) __('users.filters.platform_role_options.super_admin'),
                        'auditor' => (string) __('users.filters.platform_role_options.auditor'),
                    ])
                    ->query(function (Builder $query, array $state): Builder {
                        if (blank($state['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereExists(
                            DB::table('user_platform_roles')
                                ->join('platform_roles', 'platform_roles.id', '=', 'user_platform_roles.platform_role_id')
                                ->whereColumn('user_platform_roles.user_id', 'users.id')
                                ->where('platform_roles.name', $state['value']),
                        );
                    }),
            ])
            ->description(fn (): string => app(SeatLimit::class)->summary())
            ->headerActions([$this->inviteAction()])
            ->recordActions([ActionGroup::make([
                $this->deactivateAction(),
                $this->activateAction(),
                $this->rolesAction(),
                $this->resetFactorsAction(),
                $this->offboardAction(),
            ])->iconButton()->tooltip((string) __('users.actions.user_actions'))])
            ->bulkActions([
                BulkAction::make('activate')->label((string) __('users.actions.activate_selected'))->color('success')->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $actor = $this->actor();
                        foreach ($records as $record) {
                            if (! $this->activateWithinSeatLimit($actor, $record->getKey())) {
                                return;
                            }
                        }
                    }),
                BulkAction::make('deactivate')->label((string) __('users.actions.deactivate_selected'))->color('danger')->requiresConfirmation()
                    ->modalDescription((string) __('users.actions.deactivate_description'))
                    ->action(function (Collection $records): void {
                        $actor = $this->actor();
                        foreach ($records as $record) {
                            app(AccountAdministration::class)->activate($actor, $record->getKey(), false);
                        }
                    }),
            ])
            ->defaultSort('name')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->queryStringIdentifier('users')
            ->emptyStateHeading((string) __('users.empty.heading'))
            ->emptyStateDescription((string) __('users.empty.description'))
            ->emptyStateIcon(Heroicon::OutlinedUsers);
    }

    public function inviteAction(): Action
    {
        return Action::make('invite')->label((string) __('users.actions.invite'))->icon(Heroicon::OutlinedUserPlus)
            ->disabled(fn (): bool => ! app(SeatLimit::class)->hasRoom())
            ->tooltip(fn (): ?string => app(SeatLimit::class)->hasRoom() ? null : app(SeatLimit::class)->message())
            ->modalHeading((string) __('users.actions.invite_heading'))
            ->modalDescription((string) __('users.actions.invite_description'))
            ->modalSubmitActionLabel((string) __('users.actions.invite_submit'))
            ->schema([
                TextInput::make('email')->label((string) __('users.actions.email'))->email()->required()->maxLength(254)
                    ->helperText((string) __('users.actions.email_helper')),
            ])
            ->action(function (array $data, Action $action): void {
                $email = str($data['email'])->trim()->lower()->toString();
                try {
                    app(Invitations::class)->invite($this->actor(), $email);
                } catch (SeatLimitReached) {
                    $this->notifySeatLimitReached();
                    $action->halt();
                }
                $this->dispatch('invitations-updated')->to(RecentInvitations::class);
                $this->notifyInvitation($email);
            });
    }

    public function activateAction(): Action
    {
        return Action::make('activate')->icon(Heroicon::OutlinedCheckCircle)->requiresConfirmation()
            ->visible(fn (?User $record): bool => $record === null || ! $record->is_active)
            ->action(function (array $arguments, ?User $record): void {
                $this->activateWithinSeatLimit($this->actor(), $this->userId($arguments, $record));
            });
    }

    /**
     * Activate an account, or tell the administrator that no seat is free.
     */
    private function activateWithinSeatLimit(User $actor, string $userId): bool
    {
        try {
            app(AccountAdministration::class)->activate($actor, $userId, true);
        } catch (SeatLimitReached) {
            $this->notifySeatLimitReached();

            return false;
        }

        return true;
    }

    private function notifySeatLimitReached(): void
    {
        Notification::make()->danger()->title((string) __('licensing.seats.limit_reached'))
            ->body(app(SeatLimit::class)->message())->send();
    }

    public function deactivateAction(): Action
    {
        return Action::make('deactivate')->icon(Heroicon::OutlinedNoSymbol)->requiresConfirmation()
            ->modalDescription((string) __('users.actions.deactivate_description'))
            ->visible(fn (?User $record): bool => $record === null || $record->is_active)
            ->action(function (array $arguments, ?User $record): void {
                app(AccountAdministration::class)->activate($this->actor(), $this->userId($arguments, $record), false);
            });
    }

    public function offboardAction(): Action
    {
        return Action::make('offboard')->label((string) __('users.actions.offboard'))->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->tooltip((string) __('users.actions.offboard_description'))
            ->visible(fn (?User $record): bool => $record === null || ($record->is_active && $record->getKey() !== auth()->id()))
            ->url(fn (array $arguments, ?User $record): string => Offboarding::getUrl(['user' => $this->userId($arguments, $record)]));
    }

    public function rolesAction(): Action
    {
        return Action::make('roles')->label((string) __('users.actions.platform_roles'))->icon(Heroicon::OutlinedShieldCheck)
            ->modalDescription((string) __('users.actions.roles_description'))
            ->fillForm(function (array $arguments, ?User $record): array {
                $userId = $this->userId($arguments, $record);

                return ['roles' => DB::table('user_platform_roles')->join('platform_roles', 'platform_roles.id', '=', 'platform_role_id')->where('user_id', $userId)->pluck('name')->all()];
            })
            ->schema([CheckboxList::make('roles')->options([
                'super_admin' => (string) __('users.actions.roles_options.super_admin'),
                'auditor' => (string) __('users.actions.roles_options.auditor'),
                'vault_creator' => (string) __('users.actions.roles_options.vault_creator'),
            ])])
            ->action(function (array $arguments, ?User $record, array $data): void {
                app(AccountAdministration::class)->roles($this->actor(), $this->userId($arguments, $record), $data['roles']);
            });
    }

    public function resetFactorsAction(): Action
    {
        return Action::make('resetFactors')->label((string) __('users.actions.reset_authentication'))->icon(Heroicon::OutlinedArrowPath)
            ->color('danger')
            ->modalDescription((string) __('users.actions.reset_description'))
            ->schema([
                TextInput::make('reason')->required()->maxLength(1000)->helperText((string) __('users.actions.reason_helper')),
                TextInput::make('password')->label((string) __('users.actions.your_password'))->password()->required(),
                ConfirmationCodeInput::make('code'),
            ])
            ->action(function (array $arguments, ?User $record, array $data): void {
                try {
                    app(AccountAdministration::class)->resetFactors($this->actor(), $this->userId($arguments, $record), $data['reason'], $data['password'], $data['code']);
                } finally {
                    $this->unmountAction();
                }
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function userId(array $arguments, ?User $record): string
    {
        return $record?->getKey() ?? $arguments['id'];
    }

    private function actor(): User
    {
        Gate::authorize('manage-installation');

        /** @var User */
        return auth()->user();
    }

    private function notifyInvitation(string $email): void
    {
        $invitation = Invitation::query()->where('intended_normalized_email', $email)->latest('created_at')->first();
        if ($invitation?->failure_code !== null) {
            Notification::make()->warning()->title((string) __('users.notifications.invitation_email_failed_title'))
                ->body((string) __('users.notifications.invitation_email_failed_body', ['email' => $email]))->send();

            return;
        }
        Notification::make()->success()->title((string) __('users.notifications.invitation_sent_title'))
            ->body((string) __('users.notifications.invitation_sent_body', ['email' => $email]))->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        Gate::authorize('manage-installation');

        return [
            'deliveries' => DB::table('security_notification_deliveries')->orderByDesc('audit_sequence')->limit(25)->get(),
        ];
    }
}
