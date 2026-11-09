<?php

namespace App\Filament\Pages;

use App\Filament\FavoriteVaultNavigation;
use App\Filament\Forms\Components\VaultIconSelect;
use App\Filament\Forms\Components\VaultImageUpload;
use App\Filament\Resources\Vaults\VaultResource;
use App\Models\User;
use App\Models\Vault;
use App\Services\Favorites;
use App\Services\VaultAccess;
use App\Services\VaultAdministration;
use App\Services\VaultImages;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class MyVaults extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.my-vaults';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 1;

    /** @var array<string, array{permission: string, expires_at: Carbon|null}>|null */
    private ?array $access = null;

    /** @var array<string, bool>|null */
    private ?array $favoriteVaultIds = null;

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getTitle(): string
    {
        return (string) __('navigation.labels.my_vaults');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('navigation.labels.my_vaults');
    }

    /**
     * The "My vaults" item plus a shortcut for every favourite vault.
     *
     * This is rebuilt on every navigation mount, so it always reflects the
     * current user's favourites and never leaks into another session.
     *
     * @return array<int, NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        $user = auth()->user();

        return [
            ...parent::getNavigationItems(),
            ...($user instanceof User ? FavoriteVaultNavigation::items($user) : []),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return (string) app(VaultAccess::class)->visibleVaults($user)->count();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label((string) __('vaults.actions.new_vault'))->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => Gate::allows('create-vault'))
                ->modalHeading((string) __('vaults.my_vaults.create_modal.heading'))
                ->modalDescription((string) __('vaults.my_vaults.create_modal.description'))
                ->modalSubmitActionLabel((string) __('vaults.actions.create_vault'))
                ->schema([
                    TextInput::make('name')->required()->maxLength(100),
                    Textarea::make('description')->rows(3)->maxLength(2000)
                        ->helperText((string) __('vaults.my_vaults.create_modal.access_helper')),
                    VaultIconSelect::make('icon')->placeholder((string) __('vaults.labels.no_icon')),
                    VaultImageUpload::make('image'),
                ])
                ->action(function (array $data): void {
                    $id = app(VaultAdministration::class)->createOwn($this->user(), $data);
                    Notification::make()->success()->title((string) __('vaults.notifications.vault_created'))
                        ->body((string) __('vaults.my_vaults.created_body'))->send();
                    $this->redirect(VaultEntries::getUrl(['vault' => $id]));
                }),
            Action::make('administration')->label((string) __('vaults.actions.vault_administration'))
                ->icon(Heroicon::OutlinedRectangleStack)
                ->url(VaultResource::getUrl('index'))
                ->visible(fn (): bool => Gate::allows('manage-installation')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(app(VaultAccess::class)->visibleVaults($this->user())->with(['owner', 'image:vault_id,checksum']))
            ->columns([
                ImageColumn::make('avatar')
                    ->label('')
                    ->state(fn (Vault $record): string => app(VaultImages::class)->avatarUrl($record))
                    ->imageSize(24)
                    ->square()
                    ->extraImgAttributes(['loading' => 'lazy', 'alt' => '', 'class' => 'rounded'])
                    ->grow(false),
                TextColumn::make('name')->searchable()->sortable()
                    ->url(fn (Vault $record): string => VaultEntries::getUrl(['vault' => $record->id])),
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
                TextColumn::make('permission')
                    ->label((string) __('vaults.labels.your_access'))
                    ->badge()
                    ->state(fn (Vault $record): ?string => $this->accessMap()[$record->id]['permission'] ?? null)
                    ->formatStateUsing(fn (string $state): string => (string) __('vaults.permissions.'.$state))
                    ->colors(['gray' => 'viewer', 'info' => 'editor', 'success' => 'manager']),
                TextColumn::make('access_expires_at')
                    ->label((string) __('vaults.labels.access_expires'))
                    ->state(fn (Vault $record): ?Carbon => $this->accessMap()[$record->id]['expires_at'] ?? null)
                    ->dateTime()
                    ->placeholder((string) __('vaults.labels.no_expiry'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('owner.name')
                    ->label((string) __('vaults.labels.owner'))
                    ->placeholder('—')
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
                ]),
            ])
            ->recordActions([
                Action::make('favorite')
                    ->label(fn (Vault $record): string => $this->isFavoriteVault($record)
                        ? (string) __('favorites.actions.remove_vault')
                        : (string) __('favorites.actions.add_vault'))
                    ->icon(fn (Vault $record): Heroicon => $this->isFavoriteVault($record) ? Heroicon::Star : Heroicon::OutlinedStar)
                    ->color(fn (Vault $record): string => $this->isFavoriteVault($record) ? 'warning' : 'gray')
                    ->iconButton()
                    ->tooltip(fn (Vault $record): string => $this->isFavoriteVault($record)
                        ? (string) __('favorites.actions.remove_vault')
                        : (string) __('favorites.actions.add_vault'))
                    ->action(function (Vault $record, Component $livewire): void {
                        $state = app(Favorites::class)->toggleVault($this->user(), $record->id);
                        $this->favoriteVaultIds = null;
                        $livewire->dispatch('refresh-sidebar');
                        Notification::make()->success()->title(
                            $state ? (string) __('favorites.notifications.vault_added') : (string) __('favorites.notifications.vault_removed')
                        )->send();
                    }),
                Action::make('open')->label((string) __('vaults.actions.open'))->icon(Heroicon::OutlinedArrowRight)
                    ->iconButton()->tooltip((string) __('vaults.actions.open'))
                    ->url(fn (Vault $record): string => VaultEntries::getUrl(['vault' => $record->id])),
            ])
            ->defaultSort('name')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->queryStringIdentifier('my-vaults')
            ->emptyStateHeading((string) __('vaults.my_vaults.empty.heading'))
            ->emptyStateDescription((string) __('vaults.my_vaults.empty.description'))
            ->emptyStateIcon(Heroicon::OutlinedKey);
    }

    /**
     * @return array<string, array{permission: string, expires_at: Carbon|null}>
     */
    private function accessMap(): array
    {
        if ($this->access !== null) {
            return $this->access;
        }

        $user = $this->user();
        $vaultIds = app(VaultAccess::class)->visibleVaults($user)->pluck('id')->all();

        return $this->access = app(VaultAccess::class)->authorities($user, array_values(array_map(
            fn (mixed $id): string => (string) $id,
            $vaultIds,
        )));
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function isFavoriteVault(Vault $vault): bool
    {
        if ($this->favoriteVaultIds === null) {
            $this->favoriteVaultIds = app(Favorites::class)->favoriteVaults($this->user())
                ->pluck('id')
                ->mapWithKeys(fn (mixed $id): array => [(string) $id => true])
                ->all();
        }

        return isset($this->favoriteVaultIds[$vault->id]);
    }
}
