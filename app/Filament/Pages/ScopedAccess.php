<?php

namespace App\Filament\Pages;

use App\Models\Entry;
use App\Models\EntryGrant;
use App\Models\Folder;
use App\Models\FolderGrant;
use App\Models\User;
use App\Services\PrincipalDirectory;
use App\Services\ScopedGrants;
use App\Services\VaultAccess;
use App\Services\VaultFolders;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Folder editing and additional (additive) grants on one folder or one entry.
 *
 * For a folder this screen owns both renaming and grants, so a Manager has one
 * place to change its name and its access. Only a vault Manager may open it.
 * Grants add access and never reduce an inherited vault grant; the complete
 * vault + folder + entry trail explains exactly why a principal can see the
 * target.
 */
class ScopedAccess extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.scoped-access';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Vaults';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    #[Locked]
    #[Url(as: 'vault')]
    public string $vault = '';

    #[Locked]
    #[Url(as: 'kind')]
    public string $kind = '';

    #[Locked]
    #[Url(as: 'target')]
    public string $target = '';

    public string $folderName = '';

    private ?Folder $folder = null;

    private ?Entry $entry = null;

    public function mount(): void
    {
        $this->authorizeTarget();
        if ($this->kind === 'folder') {
            $this->folderName = $this->folderModel()->name;
        }
    }

    public function hydrate(): void
    {
        $this->folder = null;
        $this->entry = null;
        $this->authorizeTarget();
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getTitle(): string
    {
        return ($this->kind === 'folder' ? (string) __('vaults.scoped_access.title.folder') : (string) __('vaults.scoped_access.title.entry')).$this->targetLabel();
    }

    public function getSubheading(): ?string
    {
        if ($this->kind === 'folder') {
            return (string) __('vaults.scoped_access.subheading.folder');
        }

        return (string) __('vaults.scoped_access.subheading.entry');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label($this->kind === 'folder' ? (string) __('vaults.scoped_access.back.folder') : (string) __('vaults.scoped_access.back.entry'))
                ->icon(Heroicon::OutlinedArrowLeft)->color('gray')
                ->url(fn (): string => $this->backUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->grantsQuery())
            ->headerActions([$this->grantAction()])
            ->columns([
                TextColumn::make('principal')->label((string) __('vaults.labels.user_or_team'))
                    ->state(fn (Model $record): string => $this->principalLabel($record))
                    ->icon(fn (Model $record): Heroicon => $record->getAttribute('principal')?->kind === 'team' ? Heroicon::OutlinedUserGroup : Heroicon::OutlinedUser)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $query): Builder => $query
                            ->whereHas('principal.user', fn (Builder $userQuery): Builder => $userQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%"))
                            ->orWhereHas('principal.team', fn (Builder $teamQuery): Builder => $teamQuery->where('name', 'like', "%{$search}%")),
                    )),
                TextColumn::make('permission')->badge()
                    ->formatStateUsing(fn (string $state): string => (string) __('vaults.permissions.'.$state))
                    ->colors(['gray' => 'viewer', 'info' => 'editor', 'success' => 'manager']),
                TextColumn::make('expires_at')->label((string) __('vaults.labels.expiry'))->dateTime()->placeholder((string) __('vaults.labels.no_expiry'))->sortable(),
                TextColumn::make('status')->badge()
                    ->state(fn (Model $record): string => $record->getAttribute('active_slot') === null ? (string) __('vaults.statuses.history') : (string) __('vaults.statuses.active'))
                    ->color(fn (Model $record): string => $record->getAttribute('active_slot') === null ? 'gray' : 'success'),
                TextColumn::make('grantedBy.name')->label((string) __('vaults.labels.granted_by'))->placeholder((string) __('vaults.labels.unknown')),
                TextColumn::make('created_at')->label((string) __('vaults.labels.created'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => (string) __('vaults.status_filters.active'), 'history' => (string) __('vaults.status_filters.history')])
                    ->query(fn (Builder $query, array $state): Builder => match ($state['value'] ?? null) {
                        'active' => $query->whereNotNull('active_slot'),
                        'history' => $query->whereNull('active_slot'),
                        default => $query,
                    }),
            ])
            ->recordActions([$this->revokeAction()])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->emptyStateHeading((string) __('vaults.scoped_access.empty.heading'))
            ->emptyStateDescription((string) __('vaults.scoped_access.empty.description'))
            ->emptyStateIcon(Heroicon::OutlinedUserPlus);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'kind' => $this->kind,
            'targetLabel' => $this->targetLabel(),
            'vaultAccess' => app(VaultAccess::class)->vaultGrantHolders($this->vaultId()),
        ];
    }

    public function renameFolder(): void
    {
        if ($this->kind !== 'folder') {
            return;
        }

        try {
            $folder = $this->folderModel();
            app(VaultFolders::class)->rename($this->user(), $this->target, $this->folderName, $folder->lock_version);
            $this->folder = null;
            $this->folderName = $this->folderModel()->name;
            Notification::make()->success()->title((string) __('vaults.notifications.folder_renamed'))->send();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title((string) __('vaults.notifications.folder_not_renamed'))
                ->body((string) collect($exception->errors())->flatten()->first())->send();
        }
    }

    public function revoke(string $grantId): void
    {
        try {
            if ($this->kind === 'folder') {
                app(ScopedGrants::class)->revokeFolder($this->user(), $this->target, $grantId);
            } else {
                app(ScopedGrants::class)->revokeEntry($this->user(), $this->target, $grantId);
            }
            Notification::make()->success()->title((string) __('vaults.notifications.grant_revoked'))->send();
        } catch (ValidationException|AuthorizationException $exception) {
            Notification::make()->danger()->title((string) __('vaults.notifications.grant_not_revoked'))
                ->body($exception instanceof ValidationException
                    ? (string) collect($exception->errors())->flatten()->first()
                    : $exception->getMessage())
                ->send();
        }
    }

    private function grantAction(): Action
    {
        return Action::make('grant')->label((string) __('vaults.actions.add_grant'))->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading((string) __('vaults.scoped_access.grant_modal.heading'))
            ->modalDescription((string) __('vaults.scoped_access.grant_modal.replace_description'))
            ->modalSubmitActionLabel((string) __('vaults.actions.save_grant'))
            ->schema([
                Select::make('principal_id')->label((string) __('vaults.labels.user_or_team'))
                    ->options(fn (): array => $this->principalOptions())->searchable()->required(),
                Select::make('permission')
                    ->options(['viewer' => (string) __('vaults.permission_options.viewer'), 'editor' => (string) __('vaults.permission_options.editor'), 'manager' => (string) __('vaults.permission_options.manager')])
                    ->required()
                    ->helperText((string) __('vaults.scoped_access.grant_modal.authority_helper')),
                DateTimePicker::make('expires_at')->label((string) __('vaults.labels.expires_at_optional'))
                    ->seconds(false)
                    ->helperText((string) __('vaults.scoped_access.grant_modal.expiry_helper')),
            ])
            ->action(function (array $data): void {
                try {
                    $expires = $data['expires_at'] === null || $data['expires_at'] === '' ? null : (string) $data['expires_at'];
                    if ($this->kind === 'folder') {
                        app(ScopedGrants::class)->grantOnFolder($this->user(), $this->target, (string) $data['principal_id'], (string) $data['permission'], $expires);
                    } else {
                        app(ScopedGrants::class)->grantOnEntry($this->user(), $this->target, (string) $data['principal_id'], (string) $data['permission'], $expires);
                    }
                    Notification::make()->success()->title((string) __('vaults.notifications.grant_saved'))->send();
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title((string) __('vaults.notifications.grant_not_saved'))
                        ->body((string) collect($exception->errors())->flatten()->first())->send();
                }
            });
    }

    private function revokeAction(): Action
    {
        return Action::make('revoke')->label((string) __('vaults.actions.revoke'))->icon(Heroicon::OutlinedUserMinus)->color('danger')
            ->iconButton()->tooltip((string) __('vaults.actions.revoke'))
            ->requiresConfirmation()
            ->modalDescription((string) __('vaults.scoped_access.revoke_modal.description'))
            ->visible(fn (Model $record): bool => $record->getAttribute('active_slot') !== null)
            ->action(fn (Model $record) => $this->revoke((string) $record->getKey()));
    }

    private function authorizeTarget(): void
    {
        abort_unless(in_array($this->kind, ['folder', 'entry'], true), 404);
        abort_if($this->vault === '' || $this->target === '', 404);

        if ($this->kind === 'folder') {
            $this->folder = Folder::query()->where('id', $this->target)->where('vault_id', $this->vault)->first();
            abort_if($this->folder === null, 404);
        } else {
            $this->entry = Entry::query()->where('id', $this->target)->where('vault_id', $this->vault)->first();
            abort_if($this->entry === null, 404);
        }

        abort_unless(app(VaultAccess::class)->allows($this->user(), $this->vault, 'grant.create'), 404);
    }

    private function folderModel(): Folder
    {
        return $this->folder ??= Folder::query()->where('id', $this->target)->where('vault_id', $this->vault)->firstOrFail();
    }

    private function entryModel(): Entry
    {
        return $this->entry ??= Entry::query()->where('id', $this->target)->where('vault_id', $this->vault)->firstOrFail();
    }

    /**
     * @return Builder<FolderGrant>|Builder<EntryGrant>
     */
    private function grantsQuery(): Builder
    {
        if ($this->kind === 'folder') {
            return FolderGrant::query()->where('folder_id', $this->target)->with(['principal.user', 'principal.team', 'grantedBy']);
        }

        return EntryGrant::query()->where('entry_id', $this->target)->with(['principal.user', 'principal.team', 'grantedBy']);
    }

    private function vaultId(): string
    {
        return $this->vault;
    }

    private function targetLabel(): string
    {
        return $this->kind === 'folder' ? $this->folderModel()->name : $this->entryModel()->title;
    }

    private function backUrl(): string
    {
        return $this->kind === 'folder'
            ? ManageFolders::getUrl(['vault' => $this->vault])
            : EntryDetail::getUrl(['entry' => $this->target]);
    }

    private function principalLabel(Model $record): string
    {
        $principal = $record->getAttribute('principal');
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

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
