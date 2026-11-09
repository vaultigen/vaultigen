<?php

namespace App\Filament\Pages;

use App\Models\Tag;
use App\Models\User;
use App\Services\VaultAccess;
use App\Services\VaultDirectory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Vault-scoped tags. Managing the directory needs vault-scope Editor authority.
 */
class ManageTags extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-only';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Vaults';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    #[Locked]
    #[Url(as: 'vault')]
    public string $vault = '';

    public function mount(): void
    {
        $this->authorizeEditor();
    }

    public function hydrate(): void
    {
        $this->authorizeEditor();
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getTitle(): string
    {
        return (string) __('tags.title');
    }

    public function getSubheading(): string
    {
        return (string) __('tags.manage_subheading');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label((string) __('tags.back'))->icon(Heroicon::OutlinedArrowLeft)->color('gray')
                ->url(fn (): string => VaultEntries::getUrl(['vault' => $this->vault])),
            Action::make('create')->label((string) __('tags.actions.new_tag'))->icon(Heroicon::OutlinedPlus)
                ->modalHeading((string) __('tags.actions.create_heading'))
                ->modalDescription((string) __('tags.actions.create_description'))
                ->schema($this->tagSchema())
                ->action(fn (array $data) => $this->createTag($data)),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Tag::query()
                ->where(fn (Builder $query): Builder => $query->where('vault_id', $this->vault)->orWhereNull('vault_id'))
                ->withCount(['entries' => fn (Builder $query): Builder => $query
                    ->whereNull('entries.deleted_at')
                    ->where('entries.vault_id', $this->vault)]))
            ->columns([
                TextColumn::make('name')->badge()->color(fn (Tag $record): string => $record->color ?? 'gray')->searchable()->sortable(),
                TextColumn::make('scope')->badge()
                    ->state(fn (Tag $record): string => $record->isGlobal() ? (string) __('tags.scope.global') : (string) __('tags.scope.vault'))
                    ->color(fn (Tag $record): string => $record->isGlobal() ? 'info' : 'gray')
                    ->sortable(),
                TextColumn::make('entries_count')->label((string) __('tags.columns.entries'))->numeric()->sortable(),
                TextColumn::make('updated_at')->label((string) __('tags.columns.updated'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('scope')
                    ->label((string) __('tags.filters.scope'))
                    ->options([
                        Tag::SCOPE_GLOBAL => (string) __('tags.scope.global'),
                        Tag::SCOPE_VAULT => (string) __('tags.scope.vault'),
                    ]),
                TernaryFilter::make('assigned')
                    ->label((string) __('tags.filters.assigned'))
                    ->trueLabel((string) __('tags.filters.assigned_in_vault'))
                    ->falseLabel((string) __('tags.filters.not_assigned_in_vault'))
                    ->placeholder((string) __('tags.filters.any'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('entries', fn (Builder $entries): Builder => $entries
                            ->whereNull('entries.deleted_at')
                            ->where('entries.vault_id', $this->vault)),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('entries', fn (Builder $entries): Builder => $entries
                            ->whereNull('entries.deleted_at')
                            ->where('entries.vault_id', $this->vault)),
                    ),
            ])
            ->groups([
                Group::make('scope')->label((string) __('tags.groups.scope'))->collapsible()
                    ->getTitleFromRecordUsing(fn (Tag $record): string => $record->isGlobal() ? (string) __('tags.groups.global') : (string) __('tags.groups.vault'))
                    ->getKeyFromRecordUsing(fn (Tag $record): string => $record->isGlobal() ? 'global' : 'vault'),
            ])
            ->defaultGroup('scope')
            ->recordActions([
                Action::make('edit')->label((string) __('tags.actions.edit'))->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()->tooltip((string) __('tags.actions.edit'))
                    ->visible(fn (Tag $record): bool => ! $record->isGlobal())
                    ->modalHeading((string) __('tags.actions.edit_heading'))
                    ->fillForm(fn (Tag $record): array => ['name' => $record->name, 'color' => $record->color])
                    ->schema($this->tagSchema())
                    ->action(fn (Tag $record, array $data) => $this->updateTag($record, $data)),
                Action::make('delete')->label((string) __('tags.actions.delete'))->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->iconButton()->tooltip((string) __('tags.actions.delete'))
                    ->visible(fn (Tag $record): bool => ! $record->isGlobal())
                    ->requiresConfirmation()
                    ->modalHeading((string) __('tags.actions.delete_heading'))
                    ->modalDescription(fn (Tag $record): string => $this->deleteWarning($record))
                    ->modalSubmitActionLabel((string) __('tags.actions.delete_submit'))
                    ->action(fn (Tag $record) => $this->deleteTag($record)),
            ])
            ->defaultSort('name')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->emptyStateHeading((string) __('tags.empty.heading'))
            ->emptyStateDescription((string) __('tags.empty.description'))
            ->emptyStateIcon(Heroicon::OutlinedTag);
    }

    /**
     * @return array<int, Component>
     */
    private function tagSchema(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(VaultDirectory::MAX_TAG_NAME),
            Select::make('color')->options(array_combine(VaultDirectory::COLORS, array_map('ucfirst', VaultDirectory::COLORS)))
                ->placeholder((string) __('tags.placeholder.no_color')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createTag(array $data): void
    {
        try {
            app(VaultDirectory::class)->createTag($this->user(), $this->vault, $data);
            Notification::make()->success()->title((string) __('tags.notifications.created'))->send();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title((string) __('tags.notifications.not_created'))
                ->body((string) collect($exception->errors())->flatten()->first())->send();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateTag(Tag $tag, array $data): void
    {
        try {
            app(VaultDirectory::class)->updateTag($this->user(), (string) $tag->id, $data);
            Notification::make()->success()->title((string) __('tags.notifications.updated'))->send();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title((string) __('tags.notifications.not_updated'))
                ->body((string) collect($exception->errors())->flatten()->first())->send();
        }
    }

    private function deleteWarning(Tag $tag): string
    {
        $usage = app(VaultDirectory::class)->usage((string) $tag->id);
        if ($usage['entries'] === 0) {
            return (string) __('tags.warning.not_attached');
        }

        return trans_choice('tags.warning.attached_entries_vault', $usage['entries'])
            .' '.(string) __('tags.warning.removes_label');
    }

    private function deleteTag(Tag $tag): void
    {
        try {
            app(VaultDirectory::class)->deleteTag($this->user(), (string) $tag->id);
            Notification::make()->success()->title((string) __('tags.notifications.deleted'))->send();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title((string) __('tags.notifications.not_deleted'))
                ->body((string) collect($exception->errors())->flatten()->first())->send();
        }
    }

    private function authorizeEditor(): void
    {
        abort_if($this->vault === '', 404);
        abort_unless(app(VaultAccess::class)->allows($this->user(), $this->vault, 'entry.update'), 404);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
