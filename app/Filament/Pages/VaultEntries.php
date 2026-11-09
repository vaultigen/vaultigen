<?php

namespace App\Filament\Pages;

use App\Filament\Forms\Components\VaultIconSelect;
use App\Filament\Forms\Components\VaultImageUpload;
use App\Filament\Resources\Vaults\VaultResource;
use App\Models\Entry;
use App\Models\User;
use App\Models\Vault;
use App\Services\EntryPayload;
use App\Services\EntrySearch;
use App\Services\EntryVault;
use App\Services\Favorites;
use App\Services\SiteIcons\SiteIcons;
use App\Services\VaultAccess;
use App\Services\VaultAdministration;
use App\Services\VaultDirectory;
use App\Services\VaultFolders;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

class VaultEntries extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.vault-entries';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Vaults';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    #[Locked]
    #[Url(as: 'vault')]
    public string $vault = '';

    private ?Vault $resolved = null;

    /** @var array<string, bool>|null */
    private ?array $favoriteEntryIds = null;

    public function mount(): void
    {
        $this->authorizeVault();
    }

    public function hydrate(): void
    {
        $this->resolved = null;
        $this->authorizeVault();
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getTitle(): string
    {
        return $this->selectedVault()->name;
    }

    public function getSubheading(): ?string
    {
        return (string) __('entries.list.subheading');
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label((string) __('entries.list.action.my_vaults'))->icon(Heroicon::OutlinedArrowLeft)->color('gray')
                ->url(MyVaults::getUrl()),
            Action::make('create')->label((string) __('entries.list.action.new_entry'))->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => $this->canCreateEntry())
                ->url(fn (): string => EntryForm::getUrl(['vault' => $this->selectedVault()->id])),
            Action::make('favorite')
                ->label(fn (): string => $this->isFavoriteVault() ? (string) __('favorites.actions.remove_vault') : (string) __('favorites.actions.add_vault'))
                ->icon(fn (): Heroicon => $this->isFavoriteVault() ? Heroicon::Star : Heroicon::OutlinedStar)
                ->color(fn (): string => $this->isFavoriteVault() ? 'warning' : 'gray')
                ->iconButton()
                ->tooltip(fn (): string => $this->isFavoriteVault() ? (string) __('favorites.actions.remove_vault') : (string) __('favorites.actions.add_vault'))
                ->action(function (): void {
                    $state = app(Favorites::class)->toggleVault($this->user(), $this->selectedVault()->id);
                    $this->dispatch('refresh-sidebar');
                    Notification::make()->success()->title(
                        $state ? (string) __('favorites.notifications.vault_added') : (string) __('favorites.notifications.vault_removed')
                    )->send();
                }),
            ActionGroup::make([
                Action::make('about')->label((string) __('entries.list.action.about'))
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->modalHeading(fn (): string => $this->selectedVault()->name)
                    ->modalContent(fn (): View => view('filament.pages.vault-about', $this->aboutData()))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel((string) __('entries.list.action.close')),
                Action::make('folders')->label((string) __('entries.list.action.manage_folders'))->icon(Heroicon::OutlinedFolderOpen)
                    ->visible(fn (): bool => $this->canManage())
                    ->url(fn (): string => ManageFolders::getUrl(['vault' => $this->selectedVault()->id])),
                Action::make('tags')->label((string) __('entries.list.action.manage_tags'))->icon(Heroicon::OutlinedTag)
                    ->visible(fn (): bool => $this->allows('entry.update'))
                    ->url(fn (): string => ManageTags::getUrl(['vault' => $this->selectedVault()->id])),
                Action::make('trash')->label((string) __('entries.list.action.trash'))->icon(Heroicon::OutlinedTrash)
                    ->visible(fn (): bool => $this->canManage())
                    ->url(fn (): string => Trash::getUrl(['vault' => $this->selectedVault()->id])),
                Action::make('edit')->label((string) __('entries.list.action.edit_vault'))->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (): bool => $this->canManage())
                    ->fillForm(fn (): array => [
                        'name' => $this->selectedVault()->name,
                        'description' => $this->selectedVault()->description,
                        'icon' => $this->selectedVault()->icon,
                    ])
                    ->schema([
                        TextInput::make('name')->required()->maxLength(100),
                        Textarea::make('description')->rows(3)->maxLength(2000),
                        VaultIconSelect::make('icon')->placeholder((string) __('entries.list.no_icon')),
                        VaultImageUpload::make('image'),
                        Toggle::make('remove_image')->label((string) __('vaults.labels.remove_image'))
                            ->visible(fn (): bool => $this->selectedVault()->image()->exists()),
                    ])
                    ->action(function (array $data): void {
                        app(VaultAdministration::class)->update($this->user(), $this->selectedVault()->id, $data);
                        Notification::make()->success()->title((string) __('entries.list.vault_updated'))->send();
                    }),
                Action::make('members')->label((string) __('entries.list.action.manage_members'))
                    ->icon(Heroicon::OutlinedUserGroup)
                    ->visible(fn (): bool => $this->canManage())
                    ->url(fn (): string => $this->membersUrl()),
            ])
                ->label((string) __('entries.list.action.vault_actions'))
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->iconButton()
                ->tooltip((string) __('entries.list.action.vault_actions')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(app(EntryVault::class)->query($this->user(), $this->selectedVault()->id)->with(['owner', 'folder', 'tags', 'siteIcon:host,checksum']))
            ->columns([
                ImageColumn::make('site_icon')
                    ->label('')
                    ->state(fn (Entry $record): string => app(SiteIcons::class)->imageUrl($record))
                    ->imageSize(24)
                    ->square()
                    ->extraImgAttributes(['loading' => 'lazy', 'alt' => '', 'class' => 'rounded'])
                    ->grow(false),
                TextColumn::make('title')
                    ->searchable(query: fn (Builder $query, string $search): Builder => app(EntrySearch::class)->apply($query, $search))
                    ->sortable()
                    ->url(fn (Entry $record): string => EntryDetail::getUrl(['entry' => $record->id])),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => EntryPayload::typeLabels()[$state] ?? ucfirst(str_replace('_', ' ', $state)))
                    ->colors([
                        'primary' => EntryPayload::TYPE_LOGIN,
                        'info' => EntryPayload::TYPE_NOTE,
                        'warning' => EntryPayload::TYPE_SSH_KEY,
                        'success' => EntryPayload::TYPE_TOTP,
                    ]),
                TextColumn::make('folder.name')->label((string) __('entries.common.folder'))->placeholder((string) __('entries.common.no_folder'))->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tags.name')->label((string) __('entries.common.tags'))->badge()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('url_host')->label((string) __('entries.list.column.host'))->placeholder('—')->sortable()->toggleable()
                    ->url(fn (Entry $record): ?string => filled($record->url_host)
                        ? Str::sanitizeUrl('https://'.$record->url_host)
                        : null)
                    ->openUrlInNewTab(),
                TextColumn::make('owner.name')->label((string) __('entries.common.owner'))->placeholder((string) __('entries.common.unknown')),
                TextColumn::make('expires_at')->label((string) __('entries.list.column.expires'))->dateTime()->placeholder((string) __('entries.list.placeholder.no_expiry'))->sortable(),
                TextColumn::make('updated_at')->label((string) __('entries.common.updated'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->options(EntryPayload::typeLabels()),
                SelectFilter::make('folder')
                    ->label((string) __('entries.common.folder'))
                    ->options(fn (): array => $this->folderFilterOptions())
                    ->query(function (Builder $query, array $state): Builder {
                        $value = $state['value'] ?? null;
                        if ($value === null || $value === '') {
                            return $query;
                        }

                        return $value === '__root__'
                            ? $query->whereNull('entries.folder_id')
                            : $query->where('entries.folder_id', $value);
                    }),
                SelectFilter::make('tag')
                    ->label((string) __('entries.list.filter.tag'))
                    ->options(fn (): array => app(VaultDirectory::class)->tagOptions($this->user(), $this->vault))
                    ->query(function (Builder $query, array $state): Builder {
                        $value = $state['value'] ?? null;

                        return $value === null || $value === ''
                            ? $query
                            : $query->whereHas('tags', fn (Builder $tags): Builder => $tags->where('tags.id', $value));
                    }),
            ])
            ->groups([
                Group::make('folder.name')->label((string) __('entries.common.folder'))->collapsible()
                    ->getTitleFromRecordUsing(fn (Entry $record): string => $record->folder->name ?? (string) __('entries.common.no_folder'))
                    ->getKeyFromRecordUsing(fn (Entry $record): string => $record->folder_id ?? '__root__')
                    ->orderQueryUsing(function (Builder $query, string $direction): Builder {
                        $order = $direction === 'desc' ? 'desc' : 'asc';

                        return $query->orderByRaw("case when entries.folder_id is null then 0 else 1 end {$order}")
                            ->orderByRaw("(select folders.name from folders where folders.id = entries.folder_id) {$order}");
                    }),
            ])
            ->defaultGroup('folder.name')
            ->recordActions([
                Action::make('favorite')
                    ->label(fn (Entry $record): string => $this->isFavoriteEntry($record)
                        ? (string) __('favorites.actions.remove_entry')
                        : (string) __('favorites.actions.add_entry'))
                    ->icon(fn (Entry $record): Heroicon => $this->isFavoriteEntry($record) ? Heroicon::Star : Heroicon::OutlinedStar)
                    ->color(fn (Entry $record): string => $this->isFavoriteEntry($record) ? 'warning' : 'gray')
                    ->iconButton()
                    ->tooltip(fn (Entry $record): string => $this->isFavoriteEntry($record)
                        ? (string) __('favorites.actions.remove_entry')
                        : (string) __('favorites.actions.add_entry'))
                    ->action(function (Entry $record): void {
                        $state = app(Favorites::class)->toggleEntry($this->user(), $record->id);
                        $this->favoriteEntryIds = null;
                        Notification::make()->success()->title(
                            $state ? (string) __('favorites.notifications.entry_added') : (string) __('favorites.notifications.entry_removed')
                        )->send();
                    }),
                Action::make('copyUsername')->label((string) __('entries.list.action.copy_username'))->icon(Heroicon::OutlinedUser)
                    ->iconButton()->tooltip((string) __('entries.list.action.copy_username'))
                    ->visible(fn (Entry $record): bool => $record->type === EntryPayload::TYPE_LOGIN)
                    ->alpineClickHandler(fn (Entry $record): string => $this->copySecretHandler($record, 'username', (string) __('entries.fields.login.username'))),
                Action::make('copyPassword')->label((string) __('entries.list.action.copy_password'))->icon(Heroicon::OutlinedKey)
                    ->iconButton()->tooltip((string) __('entries.list.action.copy_password'))
                    ->visible(fn (Entry $record): bool => $record->type === EntryPayload::TYPE_LOGIN)
                    ->alpineClickHandler(fn (Entry $record): string => $this->copySecretHandler($record, 'password', (string) __('entries.fields.login.password'))),
                Action::make('copyPassphrase')->label((string) __('entries.list.action.copy_passphrase'))->icon(Heroicon::OutlinedKey)
                    ->iconButton()->tooltip((string) __('entries.list.action.copy_passphrase'))
                    ->visible(fn (Entry $record): bool => $record->type === EntryPayload::TYPE_SSH_KEY)
                    ->alpineClickHandler(fn (Entry $record): string => $this->copySecretHandler($record, 'passphrase', (string) __('entries.fields.ssh_key.passphrase'))),
                Action::make('open')->label((string) __('entries.list.action.open'))->icon(Heroicon::OutlinedArrowRight)
                    ->iconButton()->tooltip((string) __('entries.list.action.open'))
                    ->url(fn (Entry $record): string => EntryDetail::getUrl(['entry' => $record->id])),
            ])
            ->searchPlaceholder((string) __('entries.list.search_placeholder'))
            ->defaultSort('title')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->queryStringIdentifier('vault-entries')
            ->emptyStateHeading((string) __('entries.list.empty.heading'))
            ->emptyStateDescription((string) __('entries.list.empty.description'))
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateActions([
                Action::make('create')->label((string) __('entries.list.action.new_entry'))->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => $this->canCreateEntry())
                    ->url(fn (): string => EntryForm::getUrl(['vault' => $this->selectedVault()->id])),
                Action::make('members')->label((string) __('entries.list.action.manage_members'))->icon(Heroicon::OutlinedUserGroup)
                    ->visible(fn (): bool => $this->canManage())
                    ->url(fn (): string => $this->membersUrl()),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['vaultState' => $this->selectedVault()->state];
    }

    /**
     * Authorized folders for the filter, including empty ones, plus the root
     * group for actors with vault-scope authority.
     *
     * @return array<string, string>
     */
    private function folderFilterOptions(): array
    {
        $options = app(VaultFolders::class)->options($this->user(), $this->vault);
        if (app(VaultAccess::class)->hasVaultScope($this->user(), $this->vault)) {
            $options = ['' => ''] + $options;
            $options['__root__'] = (string) __('entries.common.no_folder');
        }

        return $options;
    }

    /**
     * A client-side click handler that copies one revealable field, delivered on
     * demand through the audited reveal endpoint. Only the endpoint URL and the
     * label are embedded; the value never reaches the page until the user clicks.
     */
    private function copySecretHandler(Entry $record, string $field, string $label): string
    {
        return 'copySecret('.Js::from(route('entries.reveal', ['entry' => $record->id, 'field' => $field])).', '.Js::from($label).')';
    }

    /**
     * @return array<string, mixed>
     */
    private function aboutData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $vault = $this->selectedVault();

        return [
            'selected' => $vault,
            'authority' => $this->authority(),
            'explanation' => app(VaultAccess::class)->explain($user, $vault->id),
            'isOwner' => $vault->private_workspace_owner_user_id === $user->id,
        ];
    }

    private function authorizeVault(): void
    {
        abort_if($this->vault === '', 404);
        abort_unless($this->allows('metadata.view'), 404);
    }

    private function selectedVault(): Vault
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $vault = app(VaultAccess::class)->visibleVaults($this->user())->find($this->vault);
        abort_if($vault === null, 404);

        return $this->resolved = $vault;
    }

    /**
     * @return array{permission: string, expires_at: Carbon|null}|null
     */
    private function authority(): ?array
    {
        return app(VaultAccess::class)->authority($this->user(), $this->selectedVault()->id);
    }

    private function canManage(): bool
    {
        return ($this->authority()['permission'] ?? null) === 'manager';
    }

    private function canCreateEntry(): bool
    {
        return app(VaultAccess::class)->canCreateInVault($this->user(), $this->vault);
    }

    private function membersUrl(): string
    {
        return VaultResource::getUrl('view', ['record' => $this->selectedVault()]);
    }

    private function allows(string $ability): bool
    {
        return app(VaultAccess::class)->allows($this->user(), $this->vault, $ability);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function isFavoriteVault(): bool
    {
        return app(Favorites::class)->isVaultFavorite($this->user(), $this->selectedVault()->id);
    }

    private function isFavoriteEntry(Entry $entry): bool
    {
        if ($this->favoriteEntryIds === null) {
            $this->favoriteEntryIds = app(Favorites::class)->favoriteEntries($this->user())
                ->pluck('id')
                ->mapWithKeys(fn (mixed $id): array => [(string) $id => true])
                ->all();
        }

        return isset($this->favoriteEntryIds[$entry->id]);
    }
}
