<?php

namespace App\Filament\Resources\Vaults\Tables;

use App\Filament\Forms\Components\ConfirmationCodeInput;
use App\Filament\Forms\Components\VaultIconSelect;
use App\Filament\Forms\Components\VaultImageUpload;
use App\Filament\Resources\Vaults\VaultResource;
use App\Models\User;
use App\Models\Vault;
use App\Services\VaultAccess;
use App\Services\VaultAdministration;
use App\Services\VaultImages;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class VaultsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar')
                    ->label('')
                    ->state(fn (Vault $record): string => app(VaultImages::class)->avatarUrl($record))
                    ->imageSize(24)
                    ->square()
                    ->extraImgAttributes(['loading' => 'lazy', 'alt' => '', 'class' => 'rounded'])
                    ->grow(false),
                TextColumn::make('name')->searchable()->sortable()
                    ->url(fn (Vault $record): string => VaultResource::getUrl('view', ['record' => $record])),
                TextColumn::make('kind')
                    ->label((string) __('vaults.labels.type'))
                    ->badge()
                    ->icon(fn (Vault $record): Heroicon => $record->kind === Vault::KIND_PRIVATE ? Heroicon::OutlinedUser : Heroicon::OutlinedUsers)
                    ->formatStateUsing(fn (string $state): string => $state === Vault::KIND_PRIVATE ? (string) __('vaults.kinds.personal') : (string) __('vaults.kinds.shared'))
                    ->colors(['info' => Vault::KIND_PRIVATE, 'primary' => Vault::KIND_SHARED])
                    ->sortable(),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => (string) __('vaults.states.'.$state))
                    ->colors(['success' => 'active', 'warning' => 'read_only', 'gray' => 'archived', 'danger' => 'pending_purge'])
                    ->sortable(),
                TextColumn::make('purge_after')->label((string) __('vaults.labels.purge_after'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('custodian.name')->label((string) __('vaults.labels.custodian'))->placeholder((string) __('vaults.labels.unknown')),
                TextColumn::make('owner.name')->label((string) __('vaults.labels.owner'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label((string) __('vaults.labels.created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')->label((string) __('vaults.labels.type'))->options([
                    Vault::KIND_PRIVATE => (string) __('vaults.kinds.personal'),
                    Vault::KIND_SHARED => (string) __('vaults.kinds.shared'),
                ]),
                SelectFilter::make('state')->options([
                    'active' => (string) __('vaults.state_filters.active'),
                    'read_only' => (string) __('vaults.state_filters.read_only'),
                    'archived' => (string) __('vaults.state_filters.archived'),
                    'pending_purge' => (string) __('vaults.state_filters.pending_purge'),
                ]),
            ])
            ->recordActions([
                self::editAction()->iconButton()->tooltip((string) __('vaults.actions.edit_vault')),
                ActionGroup::make([
                    ViewAction::make()->label((string) __('vaults.actions.open'))->icon(Heroicon::OutlinedArrowRight),
                    self::transferAction(),
                    self::archiveAction(),
                    self::reactivateAction(),
                    self::purgeAction(),
                    self::cancelPurgeAction(),
                    self::recoverAction(),
                ])->iconButton()->tooltip((string) __('vaults.actions.more_actions')),
            ])
            ->defaultSort('name')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->emptyStateHeading((string) __('vaults.vaults_table.empty.heading'))
            ->emptyStateDescription((string) __('vaults.vaults_table.empty.description'))
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck);
    }

    public static function editAction(): Action
    {
        return Action::make('edit')->label((string) __('vaults.actions.edit_vault'))->icon(Heroicon::OutlinedPencilSquare)
            ->fillForm(fn (Vault $record): array => [
                'name' => $record->name,
                'description' => $record->description,
                'icon' => $record->icon,
            ])
            ->schema([
                TextInput::make('name')->required()->maxLength(100),
                Textarea::make('description')->rows(3)->maxLength(2000),
                VaultIconSelect::make('icon')->placeholder((string) __('vaults.labels.no_icon')),
                VaultImageUpload::make('image'),
                Toggle::make('remove_image')->label((string) __('vaults.labels.remove_image'))
                    ->visible(fn (Vault $record): bool => $record->image()->exists()),
            ])
            ->visible(fn (Vault $record): bool => in_array($record->state, ['active', 'read_only'], true)
                && VaultResource::canEdit($record))
            ->action(function (Vault $record, array $data): void {
                app(VaultAdministration::class)->update(self::actor(), $record->id, $data);
                Notification::make()->success()->title((string) __('vaults.notifications.vault_updated'))->send();
            });
    }

    public static function transferAction(): Action
    {
        return Action::make('transfer')->label((string) __('vaults.actions.transfer_custodian'))->icon(Heroicon::OutlinedArrowsRightLeft)
            ->modalHeading((string) __('vaults.vaults_table.transfer_modal.heading'))
            ->modalDescription((string) __('vaults.vaults_table.transfer_modal.description'))
            ->schema([
                Select::make('primary_custodian_user_id')->label((string) __('vaults.vaults_table.transfer_modal.new_custodian'))
                    ->options(fn (): array => self::userOptions())->searchable()->required(),
            ])
            ->visible(fn (Vault $record): bool => in_array($record->state, ['active', 'read_only'], true) && self::canGovern($record))
            ->action(function (Vault $record, array $data): void {
                app(VaultAdministration::class)->transferCustodian(self::actor(), $record->id, (string) $data['primary_custodian_user_id']);
                Notification::make()->success()->title((string) __('vaults.notifications.custodian_transferred'))->send();
            });
    }

    public static function archiveAction(): Action
    {
        return Action::make('archive')->label((string) __('vaults.actions.archive'))->icon(Heroicon::OutlinedArchiveBox)
            ->requiresConfirmation()
            ->modalDescription((string) __('vaults.vaults_table.archive_modal.description'))
            ->visible(fn (Vault $record): bool => self::canGovern($record) && ! in_array($record->state, ['archived', 'pending_purge'], true))
            ->action(function (Vault $record): void {
                app(VaultAdministration::class)->setState(self::actor(), $record->id, 'archived');
                Notification::make()->success()->title((string) __('vaults.notifications.vault_archived'))->send();
            });
    }

    public static function reactivateAction(): Action
    {
        return Action::make('reactivate')->label((string) __('vaults.actions.reactivate'))->icon(Heroicon::OutlinedPlay)
            ->requiresConfirmation()
            ->visible(fn (Vault $record): bool => self::canGovern($record) && $record->state === 'archived')
            ->action(function (Vault $record): void {
                app(VaultAdministration::class)->setState(self::actor(), $record->id, 'active');
                Notification::make()->success()->title((string) __('vaults.notifications.vault_reactivated'))->send();
            });
    }

    public static function purgeAction(): Action
    {
        return Action::make('purge')->label((string) __('vaults.actions.initiate_purge'))->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription((string) __('vaults.vaults_table.purge_modal.description'))
            ->visible(fn (Vault $record): bool => self::canGovern($record) && $record->state !== 'pending_purge')
            ->action(function (Vault $record): void {
                app(VaultAdministration::class)->initiatePurge(self::actor(), $record->id);
                Notification::make()->warning()->title((string) __('vaults.notifications.vault_marked_pending_purge'))->send();
            });
    }

    public static function cancelPurgeAction(): Action
    {
        return Action::make('cancelPurge')->label((string) __('vaults.actions.cancel_purge'))->icon(Heroicon::OutlinedArrowUturnLeft)
            ->requiresConfirmation()
            ->modalDescription((string) __('vaults.vaults_table.cancel_purge_modal.description'))
            ->visible(fn (Vault $record): bool => Gate::allows('manage-installation') && $record->state === 'pending_purge')
            ->action(function (Vault $record): void {
                app(VaultAdministration::class)->cancelPurge(self::actor(), $record->id);
                Notification::make()->success()->title((string) __('vaults.notifications.vault_purge_cancelled'))->send();
            });
    }

    public static function recoverAction(): Action
    {
        return Action::make('recover')->label((string) __('vaults.actions.recover_manager'))->icon(Heroicon::OutlinedArrowPath)
            ->color('danger')
            ->visible(fn (Vault $record): bool => Gate::allows('manage-installation') && $record->state !== 'pending_purge' && self::activeManagerCount($record) === 0)
            ->modalHeading((string) __('vaults.vaults_table.recover_modal.heading'))
            ->modalDescription((string) __('vaults.vaults_table.recover_modal.description'))
            ->schema([
                Select::make('initial_manager_user_id')->label((string) __('vaults.vaults_table.recover_modal.new_manager'))
                    ->options(fn (): array => self::userOptions())->searchable()->required(),
                TextInput::make('reason')->required()->maxLength(1000)
                    ->helperText((string) __('vaults.vaults_table.recover_modal.reason_helper')),
                TextInput::make('password')->label((string) __('vaults.vaults_table.recover_modal.your_password'))->password()->required(),
                ConfirmationCodeInput::make('code'),
            ])
            ->action(function (Vault $record, array $data): void {
                app(VaultAdministration::class)->recover(
                    self::actor(),
                    $record->id,
                    (string) $data['initial_manager_user_id'],
                    (string) $data['reason'],
                    (string) $data['password'],
                    (string) $data['code'],
                );
                Notification::make()->success()->title((string) __('vaults.notifications.vault_recovered'))->send();
            });
    }

    private static function canGovern(Vault $record): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return Gate::forUser($user)->allows('manage-installation')
            || app(VaultAccess::class)->isCustodian($user, $record->id);
    }

    /** @return array<string, string> */
    private static function userOptions(): array
    {
        return User::query()->where('is_active', true)->orderBy('name')->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} ({$user->email})"])
            ->all();
    }

    private static function activeManagerCount(Vault $vault): int
    {
        return DB::table('vault_grants')
            ->where('vault_id', $vault->id)
            ->where('permission', 'manager')
            ->whereNotNull('active_slot')
            ->whereNull('revoked_at')
            ->where(fn ($expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();
    }

    private static function actor(): User
    {
        /** @var User */
        return auth()->user();
    }
}
