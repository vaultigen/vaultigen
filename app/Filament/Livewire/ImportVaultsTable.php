<?php

namespace App\Filament\Livewire;

use App\Models\ImportRun;
use App\Models\User;
use App\Services\Imports\ImportPlanEditor;
use App\Services\Imports\ImportPlanner;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\CheckboxColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
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
 * The import wizard's vault review table.
 *
 * Rows are derived from the staged run plan (custom, non-Eloquent data), and
 * every inline edit is re-resolved and persisted through the plan editor. The
 * table is refreshed by the `import-plan-updated` event the wizard and the
 * sibling tag table also listen for.
 */
class ImportVaultsTable extends Component implements HasActions, HasSchemas, HasTable
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
                $rows = collect(app(ImportPlanEditor::class)->vaultRows($this->runModel()));

                if (filled($search)) {
                    $needle = Str::lower($search);
                    $rows = $rows->filter(fn (array $row): bool => str_contains(Str::lower($row['name']), $needle));
                }

                if (in_array($sortColumn, ['name', 'entry_count', 'include'], true)) {
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
            ->queryStringIdentifier('import-vaults')
            ->columns([
                CheckboxColumn::make('include')
                    ->label((string) __('imports.fields.include'))
                    ->updateStateUsing(function (array $record, mixed $state): bool {
                        $this->persistVault((string) $record['key'], ['include' => (bool) $state]);

                        return (bool) $state;
                    }),
                TextInputColumn::make('name')
                    ->label((string) __('imports.fields.vault_name'))
                    ->rules(['max:'.ImportPlanner::MAX_NAME])
                    ->updateStateUsing(function (array $record, mixed $state): string {
                        $name = is_string($state) ? trim($state) : '';
                        $this->persistVault((string) $record['key'], ['name' => $name]);

                        return $name;
                    }),
                SelectColumn::make('custodian_user_id')
                    ->label((string) __('imports.fields.custodian'))
                    ->options(fn (): array => $this->userOptions())
                    ->searchableOptions()
                    ->updateStateUsing(function (array $record, mixed $state): ?string {
                        $value = is_string($state) && $state !== '' ? $state : null;
                        $this->persistVault((string) $record['key'], ['custodian_user_id' => $value]);

                        return $value;
                    }),
                SelectColumn::make('manager_user_id')
                    ->label((string) __('imports.fields.manager'))
                    ->options(fn (): array => $this->userOptions())
                    ->searchableOptions()
                    ->updateStateUsing(function (array $record, mixed $state): ?string {
                        $value = is_string($state) && $state !== '' ? $state : null;
                        $this->persistVault((string) $record['key'], ['manager_user_id' => $value]);

                        return $value;
                    }),
                TextColumn::make('entry_count')
                    ->label((string) __('imports.fields.entry_count'))
                    ->numeric()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->searchable()
            ->emptyStateHeading((string) __('imports.vaults.empty_heading'))
            ->emptyStateDescription((string) __('imports.vaults.empty_description'));
    }

    public function render(): View
    {
        return view('filament.livewire.import-vaults-table');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function persistVault(string $key, array $changes): void
    {
        app(ImportPlanEditor::class)->applyVaultChange($this->runModel(), $key, $changes);

        $this->cachedTableRecords = null;
        $this->dispatch('import-plan-updated');
    }

    /** @return array<string, string> */
    private function userOptions(): array
    {
        return User::query()->where('is_active', true)->orderBy('name')->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} ({$user->email})"])
            ->all();
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
