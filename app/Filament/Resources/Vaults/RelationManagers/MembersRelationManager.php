<?php

namespace App\Filament\Resources\Vaults\RelationManagers;

use App\Models\User;
use App\Models\VaultGrant;
use App\Services\PrincipalDirectory;
use App\Services\VaultAccess;
use App\Services\VaultMembership;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Membership for a single vault, shown on the vault's view page.
 *
 * Custodians and administrators may review it; only a vault Manager can
 * change it. Grants are additive and replaced grants are kept as history.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'grants';

    protected static ?string $recordTitleAttribute = 'id';

    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return (string) __('vaults.members.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        /** @var User $user */
        $user = auth()->user();

        $access = app(VaultAccess::class);

        return Gate::forUser($user)->allows('manage-installation')
            || $access->canGovern($user, $ownerRecord->getKey())
            || $access->can($user, $ownerRecord->getKey(), 'manager');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('principal')->label((string) __('vaults.labels.user_or_team'))
                    ->state(fn (VaultGrant $record): string => $this->principalLabel($record))
                    ->icon(fn (VaultGrant $record): Heroicon => $record->principal?->kind === 'team' ? Heroicon::OutlinedUserGroup : Heroicon::OutlinedUser)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $query): Builder => $query
                            ->whereHas('principal.user', fn (Builder $userQuery): Builder => $userQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%"))
                            ->orWhereHas('principal.team', fn (Builder $teamQuery): Builder => $teamQuery->where('name', 'like', "%{$search}%")),
                    )),
                TextColumn::make('permission')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => (string) __('vaults.permissions.'.$state))
                    ->colors(['gray' => 'viewer', 'info' => 'editor', 'success' => 'manager']),
                TextColumn::make('expires_at')->label((string) __('vaults.labels.expiry'))
                    ->dateTime()
                    ->placeholder((string) __('vaults.labels.no_expiry'))
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (VaultGrant $record): string => $record->active_slot === null ? (string) __('vaults.statuses.history') : (string) __('vaults.statuses.active'))
                    ->color(fn (VaultGrant $record): string => $record->active_slot === null ? 'gray' : 'success'),
                TextColumn::make('grantedBy.name')->label((string) __('vaults.labels.granted_by'))->placeholder((string) __('vaults.labels.unknown')),
                TextColumn::make('created_at')->label((string) __('vaults.labels.created'))->dateTime()->sortable(),
                TextColumn::make('revocation_reason')->label((string) __('vaults.labels.note'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['active' => (string) __('vaults.status_filters.active'), 'history' => (string) __('vaults.status_filters.history')])
                    ->query(function (Builder $query, array $state): Builder {
                        return match ($state['value'] ?? null) {
                            'active' => $query->whereNotNull('active_slot'),
                            'history' => $query->whereNull('active_slot'),
                            default => $query,
                        };
                    }),
            ])
            ->headerActions([$this->grantAction()])
            ->recordActions([$this->revokeAction()])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->emptyStateHeading((string) __('vaults.members.empty.heading'))
            ->emptyStateDescription((string) __('vaults.members.empty.description'))
            ->emptyStateIcon(Heroicon::OutlinedUserGroup);
    }

    public function grantAction(): Action
    {
        return Action::make('grant')->label((string) __('vaults.actions.add_or_replace_grant'))->icon(Heroicon::OutlinedUserPlus)
            ->visible(fn (): bool => $this->canManage())
            ->modalHeading((string) __('vaults.members.grant_modal.heading'))
            ->modalDescription((string) __('vaults.members.grant_modal.replace_description'))
            ->modalSubmitActionLabel((string) __('vaults.actions.save_grant'))
            ->schema([
                Select::make('principal_id')->label((string) __('vaults.labels.user_or_team'))
                    ->options(fn (): array => $this->principalOptions())->searchable()->required(),
                Radio::make('permission')
                    ->options(['viewer' => (string) __('vaults.permission_options.viewer'), 'editor' => (string) __('vaults.permission_options.editor'), 'manager' => (string) __('vaults.permission_options.manager')])
                    ->required()
                    ->helperText((string) __('vaults.members.grant_modal.authority_helper')),
                DateTimePicker::make('expires_at')->label((string) __('vaults.labels.expires_at_optional'))
                    ->seconds(false)
                    ->helperText((string) __('vaults.members.grant_modal.expiry_helper')),
            ])
            ->action(function (array $data): void {
                app(VaultMembership::class)->grant(
                    $this->actor(),
                    $this->getOwnerRecord()->getKey(),
                    (string) $data['principal_id'],
                    (string) $data['permission'],
                    $data['expires_at'] === null || $data['expires_at'] === '' ? null : (string) $data['expires_at'],
                );
                Notification::make()->success()->title((string) __('vaults.notifications.grant_saved'))->send();
            });
    }

    public function revokeAction(): Action
    {
        return Action::make('revoke')->label((string) __('vaults.actions.revoke'))->icon(Heroicon::OutlinedUserMinus)->color('danger')
            ->iconButton()->tooltip((string) __('vaults.actions.revoke'))
            ->requiresConfirmation()
            ->modalDescription((string) __('vaults.members.revoke_modal.description'))
            ->visible(fn (?VaultGrant $record): bool => $this->canManage() && ($record === null || $record->active_slot !== null))
            ->action(function (array $arguments, ?VaultGrant $record): void {
                $grantId = $record?->getKey() ?? $arguments['id'];
                try {
                    app(VaultMembership::class)->revoke($this->actor(), $this->getOwnerRecord()->getKey(), (string) $grantId);
                    Notification::make()->success()->title((string) __('vaults.notifications.grant_revoked'))->send();
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title((string) __('vaults.notifications.grant_not_revoked'))
                        ->body((string) collect($exception->errors())->flatten()->first())->send();
                }
            });
    }

    private function canManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(VaultAccess::class)->can($user, $this->getOwnerRecord()->getKey(), 'manager');
    }

    private function principalLabel(VaultGrant $record): string
    {
        $principal = $record->principal;
        $user = $principal?->user;
        if ($user !== null) {
            return "{$user->name} ({$user->email})";
        }

        $team = $principal?->team;

        return (string) __('vaults.principal.team_name', ['name' => $team->name ?? (string) __('vaults.labels.unknown')]);
    }

    /** @return array<string, string> */
    private function principalOptions(): array
    {
        $options = [];
        foreach (app(PrincipalDirectory::class)->selectablePrincipals() as $principal) {
            $options[$principal['id']] = $principal['label'];
        }

        return $options;
    }

    private function actor(): User
    {
        /** @var User */
        return auth()->user();
    }
}
