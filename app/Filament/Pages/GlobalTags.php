<?php

namespace App\Filament\Pages;

use App\Models\Tag;
use App\Models\User;
use App\Models\Vault;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Installation-wide tags. Only a Super Admin may create, rename, or delete
 * them; any vault may use them.
 */
class GlobalTags extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-only';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 20;

    public function mount(): void
    {
        Gate::authorize('manage-installation');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('manage-installation');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) Tag::query()->global()->count();
    }

    public function getTitle(): string
    {
        return (string) __('tags.title');
    }

    public function getSubheading(): string
    {
        return (string) __('tags.subheading');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label((string) __('tags.actions.new_global_tag'))->icon(Heroicon::OutlinedPlus)
                ->modalHeading((string) __('tags.actions.create_global_heading'))
                ->schema($this->tagSchema())
                ->action(fn (array $data) => $this->createTag($data)),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Tag::query()->global()
                ->withCount(['entries' => fn (Builder $query): Builder => $query->whereNull('entries.deleted_at')])
                ->addSelect(['vaults_count' => DB::table('entry_tag')
                    ->join('entries', 'entries.id', '=', 'entry_tag.entry_id')
                    ->whereColumn('entry_tag.tag_id', 'tags.id')
                    ->whereNull('entries.deleted_at')
                    ->selectRaw('count(distinct entry_tag.vault_id)'),
                ]))
            ->columns([
                TextColumn::make('name')->badge()->color(fn (Tag $record): string => $record->color ?? 'gray')->searchable()->sortable(),
                TextColumn::make('entries_count')->label((string) __('tags.columns.entries'))->numeric()->sortable(),
                TextColumn::make('vaults_count')->label((string) __('tags.columns.vaults'))->numeric()->sortable(),
                TextColumn::make('updated_at')->label((string) __('tags.columns.updated'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('assigned')
                    ->label((string) __('tags.filters.assigned'))
                    ->trueLabel((string) __('tags.filters.assigned'))
                    ->falseLabel((string) __('tags.filters.not_assigned'))
                    ->placeholder((string) __('tags.filters.any'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('entries', fn (Builder $entries): Builder => $entries->whereNull('entries.deleted_at')),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('entries', fn (Builder $entries): Builder => $entries->whereNull('entries.deleted_at')),
                    ),
                SelectFilter::make('vault')
                    ->label((string) __('tags.filters.used_in_vault'))
                    ->options(fn (): array => Vault::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(function (Builder $query, array $state): Builder {
                        $value = $state['value'] ?? null;

                        return blank($value)
                            ? $query
                            : $query->whereHas('entries', fn (Builder $entries): Builder => $entries
                                ->whereNull('entries.deleted_at')
                                ->where('entries.vault_id', $value));
                    }),
            ])
            ->recordActions([
                Action::make('edit')->label((string) __('tags.actions.rename'))->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()->tooltip((string) __('tags.actions.rename'))
                    ->modalHeading((string) __('tags.actions.rename_global_heading'))
                    ->fillForm(fn (Tag $record): array => ['name' => $record->name, 'color' => $record->color])
                    ->schema($this->tagSchema())
                    ->action(fn (Tag $record, array $data) => $this->updateTag($record, $data)),
                Action::make('delete')->label((string) __('tags.actions.delete'))->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->iconButton()->tooltip((string) __('tags.actions.delete'))
                    ->requiresConfirmation()
                    ->modalHeading((string) __('tags.actions.delete_global_heading'))
                    ->modalDescription(fn (Tag $record): string => $this->deleteWarning($record))
                    ->modalSubmitActionLabel((string) __('tags.actions.delete_submit'))
                    ->action(fn (Tag $record) => $this->deleteTag($record)),
            ])
            ->defaultSort('name')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->emptyStateHeading((string) __('tags.empty.global_heading'))
            ->emptyStateDescription((string) __('tags.empty.global_description'))
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

    private function deleteWarning(Tag $tag): string
    {
        $usage = app(VaultDirectory::class)->usage((string) $tag->id);
        if ($usage['entries'] === 0) {
            return (string) __('tags.warning.not_attached');
        }

        $vaults = array_column($usage['vaults'], 'name');
        $where = $vaults === [] ? '' : ' '.(string) __('tags.warning.in_vaults', ['vaults' => implode(', ', $vaults)]);

        return trans_choice('tags.warning.attached_entries', $usage['entries'])
            .' '.trans_choice('tags.warning.across_vaults', count($usage['vaults']))
            .$where
            .' '.(string) __('tags.warning.removes_label');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createTag(array $data): void
    {
        try {
            app(VaultDirectory::class)->createTag($this->user(), null, $data);
            Notification::make()->success()->title((string) __('tags.notifications.global_created'))->send();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title((string) __('tags.notifications.global_not_created'))
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
            Notification::make()->success()->title((string) __('tags.notifications.global_updated'))->send();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title((string) __('tags.notifications.global_not_updated'))
                ->body((string) collect($exception->errors())->flatten()->first())->send();
        }
    }

    private function deleteTag(Tag $tag): void
    {
        app(VaultDirectory::class)->deleteTag($this->user(), (string) $tag->id);
        Notification::make()->success()->title((string) __('tags.notifications.global_deleted'))->send();
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
