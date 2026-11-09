<?php

namespace App\Filament\Livewire;

use App\Models\Invitation;
use App\Models\User;
use App\Services\Invitations;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

class RecentInvitations extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function mount(): void
    {
        Gate::authorize('manage-installation');
    }

    public function hydrate(): void
    {
        Gate::authorize('manage-installation');
    }

    #[On('invitations-updated')]
    public function refreshInvitations(): void {}

    public function table(Table $table): Table
    {
        return $table
            ->query(Invitation::query()->with('invitedBy'))
            ->queryStringIdentifier('invitations')
            ->columns([
                TextColumn::make('intended_normalized_email')->label((string) __('users.invitations.email'))
                    ->searchable()
                    ->copyable()
                    ->weight(FontWeight::Medium),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (Invitation $record): string => $this->status($record))
                    ->colors([
                        'warning' => (string) __('users.invitations.status.pending'),
                        'success' => (string) __('users.invitations.status.accepted'),
                        'gray' => (string) __('users.invitations.status.expired'),
                        'danger' => (string) __('users.invitations.status.cancelled'),
                    ]),
                TextColumn::make('delivery')
                    ->badge()
                    ->state(fn (Invitation $record): string => $this->delivery($record))
                    ->colors([
                        'success' => (string) __('users.invitations.delivery_state.sent'),
                        'warning' => (string) __('users.invitations.delivery_state.pending'),
                        'danger' => (string) __('users.invitations.delivery_state.failed'),
                        'gray' => (string) __('users.invitations.delivery_state.not_configured'),
                    ])
                    ->tooltip(fn (Invitation $record): ?string => $record->failure_code),
                TextColumn::make('invitedBy.name')->label((string) __('users.invitations.invited_by'))
                    ->description(fn (Invitation $record): ?string => $record->invitedBy?->email)
                    ->placeholder((string) __('users.invitations.removed_account'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'invitedBy',
                        fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"),
                    )),
                TextColumn::make('created_at')->label((string) __('users.invitations.created'))
                    ->since()
                    ->tooltip(fn (Invitation $record): ?string => $record->created_at?->toDayDateTimeString())
                    ->sortable(),
                TextColumn::make('expires_at')->label((string) __('users.invitations.expires'))
                    ->since()
                    ->tooltip(fn (Invitation $record): string => $record->expires_at->toDayDateTimeString())
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => (string) __('users.invitations.status.pending'),
                        'accepted' => (string) __('users.invitations.status.accepted'),
                        'expired' => (string) __('users.invitations.status.expired'),
                        'cancelled' => (string) __('users.invitations.status.cancelled'),
                    ])
                    ->query(function (Builder $query, array $state): Builder {
                        return match ($state['value'] ?? null) {
                            'pending' => $query->whereNull('consumed_at')->whereNull('cancelled_at')->where('expires_at', '>', now()),
                            'accepted' => $query->whereNotNull('consumed_at'),
                            'expired' => $query->whereNull('consumed_at')->whereNull('cancelled_at')->where('expires_at', '<=', now()),
                            'cancelled' => $query->whereNotNull('cancelled_at'),
                            default => $query,
                        };
                    }),
                SelectFilter::make('delivery')
                    ->options([
                        'sent' => (string) __('users.invitations.delivery.sent'),
                        'pending' => (string) __('users.invitations.delivery.pending'),
                        'failed' => (string) __('users.invitations.delivery.failed'),
                    ])
                    ->query(function (Builder $query, array $state): Builder {
                        return match ($state['value'] ?? null) {
                            'sent' => $query->whereNotNull('delivered_at'),
                            'pending' => $query->whereNull('delivered_at')->whereNull('failure_code'),
                            'failed' => $query->whereNull('delivered_at')->whereNotNull('failure_code'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                $this->resendAction(),
                $this->cancelAction(),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([10, 25, 50])
            ->emptyStateHeading((string) __('users.invitations.empty_heading'))
            ->emptyStateDescription((string) __('users.invitations.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedEnvelope);
    }

    public function resendAction(): Action
    {
        return Action::make('resend')->label((string) __('users.invitations.resend'))->icon(Heroicon::OutlinedPaperAirplane)->color('gray')
            ->iconButton()->tooltip((string) __('users.invitations.resend'))
            ->visible(fn (Invitation $record): bool => $record->consumed_at === null && $record->cancelled_at === null)
            ->requiresConfirmation()
            ->modalDescription((string) __('users.invitations.resend_description'))
            ->action(function (Invitation $record): void {
                app(Invitations::class)->resend($this->actor(), (string) $record->getKey());
                $invitation = Invitation::query()->where('intended_normalized_email', $record->intended_normalized_email)->latest('created_at')->first();
                if ($invitation?->failure_code !== null) {
                    Notification::make()->warning()->title((string) __('users.invitations.replaced_email_failed_title'))
                        ->body((string) __('users.invitations.replaced_email_failed_body', ['email' => $record->intended_normalized_email]))->send();

                    return;
                }
                Notification::make()->success()->title((string) __('users.invitations.resent_title'))
                    ->body((string) __('users.invitations.resent_body', ['email' => $record->intended_normalized_email]))->send();
            });
    }

    public function cancelAction(): Action
    {
        return Action::make('cancel')->label((string) __('users.invitations.cancel'))->icon(Heroicon::OutlinedXCircle)->color('danger')
            ->iconButton()->tooltip((string) __('users.invitations.cancel'))
            ->visible(fn (Invitation $record): bool => $record->consumed_at === null && $record->cancelled_at === null)
            ->requiresConfirmation()
            ->modalDescription((string) __('users.invitations.cancel_description'))
            ->action(function (Invitation $record): void {
                app(Invitations::class)->cancel($this->actor(), (string) $record->getKey());
                Notification::make()->success()->title((string) __('users.invitations.cancelled_title'))
                    ->body((string) __('users.invitations.cancelled_body', ['email' => $record->intended_normalized_email]))->send();
            });
    }

    public function render(): View
    {
        return view('filament.livewire.recent-invitations');
    }

    private function status(Invitation $invitation): string
    {
        return match (true) {
            $invitation->consumed_at !== null => (string) __('users.invitations.status.accepted'),
            $invitation->cancelled_at !== null => (string) __('users.invitations.status.cancelled'),
            $invitation->expires_at->isPast() => (string) __('users.invitations.status.expired'),
            default => (string) __('users.invitations.status.pending'),
        };
    }

    private function delivery(Invitation $invitation): string
    {
        return match (true) {
            $invitation->delivered_at !== null => (string) __('users.invitations.delivery_state.sent'),
            $invitation->failure_code === 'mail_transport_unconfigured' => (string) __('users.invitations.delivery_state.not_configured'),
            $invitation->failure_code !== null => (string) __('users.invitations.delivery_state.failed'),
            default => (string) __('users.invitations.delivery_state.pending'),
        };
    }

    private function actor(): User
    {
        Gate::authorize('manage-installation');

        /** @var User */
        return auth()->user();
    }
}
