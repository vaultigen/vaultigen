<?php

namespace App\Filament\Livewire;

use App\Models\ImportRun;
use App\Services\Imports\ImportPlanEditor;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\CheckboxColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The import wizard's tag review table.
 *
 * Rows are derived from the staged run plan. The include checkbox and the
 * global/local scope are editable inline; both are persisted through the plan
 * editor and broadcast with `import-plan-updated`.
 */
class ImportTagsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public string $run = '';

    public function mount(string $run): void
    {
        $this->run = $run;
        $this->authorizeTable();
    }

    public function hydrate(): void
    {
        $this->authorizeTable();
    }

    #[On('import-plan-updated')]
    public function refreshPlan(): void
    {
        $this->cachedTableRecords = null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (
                ?string $search,
                ?string $sortColumn,
                ?string $sortDirection,
                int|string $page,
                int|string $recordsPerPage,
            ): LengthAwarePaginator {
                $rows = collect(app(ImportPlanEditor::class)->tagRows($this->runModel()));

                if (filled($search)) {
                    $needle = Str::lower($search);
                    $rows = $rows->filter(fn (array $row): bool => str_contains(Str::lower($row['name']), $needle));
                }

                if (in_array($sortColumn, ['name', 'entry_count', 'vault_count', 'include'], true)) {
                    $rows = $rows->sortBy($sortColumn, SORT_REGULAR, $sortDirection === 'desc');
                }

                $total = $rows->count();
                $perPage = is_numeric($recordsPerPage) ? max(1, (int) $recordsPerPage) : max(1, $total);
                $page = max(1, (int) $page);

                return new LengthAwarePaginator(
                    items: $rows->forPage($page, $perPage)->values()->all(),
                    total: $total,
                    perPage: $perPage,
                    currentPage: $page,
                    options: ['pageName' => $this->getTablePaginationPageName()],
                );
            })
            ->queryStringIdentifier('import-tags')
            ->columns([
                CheckboxColumn::make('include')
                    ->label((string) __('imports.fields.include'))
                    ->updateStateUsing(function (array $record, mixed $state): bool {
                        $this->persistTag((string) $record['key'], ['include' => (bool) $state]);

                        return (bool) $state;
                    }),
                TextColumn::make('name')
                    ->label((string) __('imports.fields.tag_name'))
                    ->searchable(),
                TextColumn::make('entry_count')
                    ->label((string) __('imports.fields.entry_count'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('vault_count')
                    ->label((string) __('imports.fields.vault_count'))
                    ->numeric()
                    ->sortable(),
                SelectColumn::make('scope')
                    ->label((string) __('imports.fields.tag_scope'))
                    ->options(fn (): array => [
                        'global' => (string) __('imports.scopes.global'),
                        'local' => (string) __('imports.scopes.local'),
                    ])
                    ->native(false)
                    ->updateStateUsing(function (array $record, mixed $state): string {
                        $scope = $state === 'global' ? 'global' : 'local';
                        $this->persistTag((string) $record['key'], ['scope' => $scope]);

                        return $scope;
                    }),
            ])
            ->defaultSort('name')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->searchable()
            ->emptyStateHeading((string) __('imports.tags.empty_heading'))
            ->emptyStateDescription((string) __('imports.tags.empty_description'));
    }

    public function render(): View
    {
        return view('filament.livewire.import-tags-table');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function persistTag(string $key, array $changes): void
    {
        app(ImportPlanEditor::class)->applyTagChange($this->runModel(), $key, $changes);

        $this->cachedTableRecords = null;
        $this->dispatch('import-plan-updated');
    }

    private function runModel(): ImportRun
    {
        return ImportRun::query()->findOrFail($this->run);
    }

    private function authorizeTable(): void
    {
        Gate::authorize('manage-installation');

        $this->runModel();
    }
}
