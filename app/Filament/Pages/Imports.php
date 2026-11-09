<?php

namespace App\Filament\Pages;

use App\Models\ImportRun;
use App\Models\User;
use App\Services\Imports\ImportRegistry;
use App\Services\Imports\ImportRunLifecycle;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The source-agnostic Import hub.
 *
 * It lists every run and hosts the plain-POST upload form, which shows the
 * credential fields the selected source declares. Secret values an importer
 * needs (such as the sysPass export password here and master password on the
 * confirm screen) are submitted in plain HTTP forms so they never enter
 * Livewire state, a snapshot, a session, or the database.
 */
class Imports extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.imports';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?int $navigationSort = 50;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-installation');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! Gate::allows('manage-installation')) {
            return null;
        }

        $count = ImportRun::query()
            ->whereIn('status', [
                ImportRun::STATUS_STAGING,
                ImportRun::STATUS_STAGED,
                ImportRun::STATUS_QUEUED,
                ImportRun::STATUS_IMPORTING,
            ])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public function mount(): void
    {
        Gate::authorize('manage-installation');
    }

    public function getTitle(): string
    {
        return (string) __('imports.title');
    }

    public function getSubheading(): string
    {
        return (string) __('imports.subheading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ImportRun::query()->with('requestedBy'))
            ->columns([
                TextColumn::make('source_format')->label((string) __('imports.columns.source'))
                    ->formatStateUsing(fn (string $state): string => $this->sourceLabel($state)),
                TextColumn::make('original_filename')->label((string) __('imports.columns.file'))
                    ->placeholder((string) __('imports.values.none'))
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => (string) __('imports.statuses.'.$state))
                    ->colors([
                        'gray' => ImportRun::STATUS_STAGING,
                        'warning' => ImportRun::STATUS_STAGED,
                        'info' => ImportRun::STATUS_QUEUED,
                        'primary' => ImportRun::STATUS_IMPORTING,
                        'success' => ImportRun::STATUS_COMPLETED,
                        'danger' => ImportRun::STATUS_FAILED,
                    ]),
                TextColumn::make('counters')->label((string) __('imports.columns.result'))
                    ->state(fn (ImportRun $record): string => $this->countersLabel($record))
                    ->toggleable(),
                TextColumn::make('requestedBy.name')->label((string) __('imports.columns.requested_by'))
                    ->placeholder((string) __('imports.values.none'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label((string) __('imports.columns.created'))
                    ->dateTime('Y-m-d H:i', 'UTC')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label((string) __('imports.filters.status'))
                    ->native(false)
                    ->options(fn (): array => collect(ImportRun::STATUSES)
                        ->mapWithKeys(fn (string $status): array => [$status => (string) __('imports.statuses.'.$status)])
                        ->all()),
            ])
            ->recordActions([
                Action::make('open')->label((string) __('imports.actions.review'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (ImportRun $record): bool => $record->status === ImportRun::STATUS_STAGED)
                    ->url(fn (ImportRun $record): string => ImportWizard::getUrl(['run' => $record->id])),
                Action::make('report')->label((string) __('imports.actions.report'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->visible(fn (ImportRun $record): bool => $record->isTerminal() || $record->isRunning())
                    ->url(fn (ImportRun $record): string => ImportReport::getUrl(['run' => $record->id])),
                Action::make('cancel')->label((string) __('imports.actions.cancel'))
                    ->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading((string) __('imports.actions.cancel'))
                    ->modalDescription((string) __('imports.cancel.description'))
                    ->modalSubmitActionLabel((string) __('imports.actions.cancel'))
                    ->visible(fn (ImportRun $record): bool => ! $record->isTerminal())
                    ->action(fn (ImportRun $record) => $this->cancel($record)),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([10, 25, 50])
            ->emptyStateHeading((string) __('imports.empty.heading'))
            ->emptyStateDescription((string) __('imports.empty.description'))
            ->emptyStateIcon(Heroicon::OutlinedArrowDownTray);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        Gate::authorize('manage-installation');

        $registry = app(ImportRegistry::class);
        $sources = $registry->options();

        $sourceDetails = [];
        $credentialFields = [];
        foreach (array_keys($sources) as $source) {
            $driver = $registry->resolve($source);
            $hintKey = 'imports.upload.hints.'.$source;
            $sourceDetails[$source] = [
                'fields' => $driver->stagingCredentialFields(),
                'accept' => implode(',', $driver->acceptedFileTypes()),
                'hint' => trans()->has($hintKey) ? (string) __($hintKey) : null,
            ];
            foreach ($driver->stagingCredentialFields() as $field) {
                $credentialFields[$field] = true;
            }
        }

        return [
            'sources' => $sources,
            'sourceDetails' => $sourceDetails,
            'credentialFields' => array_keys($credentialFields),
        ];
    }

    private function cancel(ImportRun $run): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        app(ImportRunLifecycle::class)->cancel($run, $actor);

        $title = $run->refresh()->status === ImportRun::STATUS_IMPORTING
            ? (string) __('imports.notifications.cancel_requested')
            : (string) __('imports.notifications.cancelled');

        Notification::make()->success()->title($title)->send();
    }

    private function sourceLabel(string $source): string
    {
        $key = 'imports.sources.'.$source;

        return trans()->has($key) ? (string) __($key) : $source;
    }

    private function countersLabel(ImportRun $run): string
    {
        $counters = is_array($run->counters) ? $run->counters : null;
        if ($counters === null) {
            return (string) __('imports.values.none');
        }

        return (string) __('imports.result', [
            'entries' => (int) ($counters['entries'] ?? 0),
            'vaults' => (int) ($counters['vaults'] ?? 0),
            'failures' => $run->failure_count,
        ]);
    }
}
