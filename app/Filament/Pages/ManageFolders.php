<?php

namespace App\Filament\Pages;

use App\Models\Folder;
use App\Models\User;
use App\Services\TrashLifecycle;
use App\Services\VaultAccess;
use App\Services\VaultFolders;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Manager-only folder administration for one vault.
 *
 * Lists every folder, including empty ones, and owns create. Renaming and folder
 * grants are managed together on the folder edit page opened by the Edit action.
 * Deletion belongs to the later trash lifecycle, never inline in the entry form.
 */
class ManageFolders extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-only';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Vaults';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    #[Locked]
    #[Url(as: 'vault')]
    public string $vault = '';

    public function mount(): void
    {
        $this->authorizeManager();
    }

    public function hydrate(): void
    {
        $this->authorizeManager();
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getTitle(): string
    {
        return (string) __('folders.title');
    }

    public function getSubheading(): ?string
    {
        return (string) __('folders.subheading');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label((string) __('folders.actions.entries'))->icon(Heroicon::OutlinedArrowLeft)->color('gray')
                ->url(fn (): string => VaultEntries::getUrl(['vault' => $this->vault])),
            Action::make('trash')->label((string) __('folders.actions.trash'))->icon(Heroicon::OutlinedTrash)->color('gray')
                ->url(fn (): string => Trash::getUrl(['vault' => $this->vault])),
            Action::make('create')->label((string) __('folders.actions.new_folder'))->icon(Heroicon::OutlinedPlus)
                ->modalHeading((string) __('folders.create_modal.heading'))
                ->modalDescription((string) __('folders.create_modal.description'))
                ->schema([TextInput::make('name')->required()->maxLength(VaultFolders::MAX_NAME)])
                ->action(fn (array $data) => $this->createFolder((string) $data['name'])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Folder::query()->where('vault_id', $this->vault)->whereNull('deleted_at')
                ->withCount(['entries' => fn (Builder $query): Builder => $query->whereNull('deleted_at')]))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('entries_count')->label((string) __('folders.columns.entries'))->numeric()->sortable(),
                TextColumn::make('updated_at')->label((string) __('folders.columns.updated'))->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('edit')->label((string) __('folders.actions.edit'))->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()->tooltip((string) __('folders.actions.edit'))
                    ->url(fn (Folder $record): string => ScopedAccess::getUrl(['vault' => $this->vault, 'kind' => 'folder', 'target' => $record->id])),
                Action::make('entry')->label((string) __('folders.actions.new_entry'))->icon(Heroicon::OutlinedDocumentPlus)
                    ->iconButton()->tooltip((string) __('folders.actions.new_entry'))
                    ->visible(fn (Folder $record): bool => app(VaultAccess::class)->canCreateEntry($this->user(), $this->vault, $record->id))
                    ->url(fn (Folder $record): string => EntryForm::getUrl(['vault' => $this->vault, 'folder' => $record->id])),
                Action::make('delete')->label((string) __('folders.actions.delete'))->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->iconButton()->tooltip((string) __('folders.actions.delete'))
                    ->visible(fn (Folder $record): bool => app(VaultAccess::class)->allowsOnFolder($this->user(), $record->id, 'folder.delete'))
                    ->requiresConfirmation()
                    ->modalDescription((string) __('folders.delete_modal.description'))
                    ->action(fn (Folder $record) => $this->deleteFolder($record)),
            ])
            ->defaultSort('name')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->emptyStateHeading((string) __('folders.empty.heading'))
            ->emptyStateDescription((string) __('folders.empty.description'))
            ->emptyStateIcon(Heroicon::OutlinedFolderOpen)
            ->emptyStateActions([
                Action::make('create')->label((string) __('folders.actions.new_folder'))->icon(Heroicon::OutlinedPlus)
                    ->schema([TextInput::make('name')->required()->maxLength(VaultFolders::MAX_NAME)])
                    ->action(fn (array $data) => $this->createFolder((string) $data['name'])),
            ]);
    }

    private function createFolder(string $name): void
    {
        try {
            app(VaultFolders::class)->create($this->user(), $this->vault, $name);
            Notification::make()->success()->title((string) __('folders.notifications.created'))->send();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title((string) __('folders.notifications.not_created'))
                ->body((string) collect($exception->errors())->flatten()->first())->send();
        }
    }

    private function deleteFolder(Folder $folder): void
    {
        try {
            app(TrashLifecycle::class)->trashFolder($this->user(), (string) $folder->id);
            Notification::make()->success()->title((string) __('folders.notifications.deleted'))->send();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title((string) __('folders.notifications.not_deleted'))
                ->body((string) collect($exception->errors())->flatten()->first())->send();
        }
    }

    private function authorizeManager(): void
    {
        abort_if($this->vault === '', 404);
        abort_unless(app(VaultAccess::class)->allows($this->user(), $this->vault, 'folder.create'), 404);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
