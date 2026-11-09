<?php

namespace App\Filament\Pages;

use App\Models\ImportRun;
use App\Models\Vault;
use App\Services\InstallationHealth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The redacted import report.
 *
 * It shows counters, per-entry and per-attachment failure reasons, and links
 * to the created vaults. It never contains a password, account key, complete
 * URL, note, attachment filename, or ciphertext: the run only stores
 * non-secret metadata. Runs recorded before failures had a `kind` and a
 * `context` show their stored `client` instead.
 */
class ImportReport extends Page
{
    protected string $view = 'filament.pages.import-report';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    #[Locked]
    #[Url(as: 'run')]
    public string $run = '';

    private ?ImportRun $resolved = null;

    public function mount(): void
    {
        $this->authorizePage();
    }

    public function hydrate(): void
    {
        $this->resolved = null;
        $this->authorizePage();
    }

    /**
     * Re-read the run for the progress poll. `hydrate()` already drops the
     * cached model; this explicit entry point keeps the intent obvious and
     * gives the poll a stable method name.
     */
    public function refresh(): void
    {
        $this->resolved = null;
    }

    public static function canAccess(): bool
    {
        return Gate::allows('manage-installation');
    }

    public function getTitle(): string
    {
        return (string) __('imports.report.title');
    }

    public function getSubheading(): string
    {
        return (string) __('imports.report.subheading');
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label((string) __('imports.report.back'))
                ->icon(Heroicon::OutlinedArrowLeft)->color('gray')
                ->url(fn (): string => Imports::getUrl()),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        Gate::authorize('manage-installation');

        $run = $this->runModel();
        $counters = is_array($run->counters) ? $run->counters : [];
        $failures = is_array($run->failures) ? $run->failures : [];

        $progressTotal = (int) ($run->progress_total ?? 0);
        $progressDone = min($progressTotal, (int) ($counters['entries'] ?? 0) + (int) $run->failure_count);

        return [
            'status' => (string) $run->status,
            'statusLabel' => (string) __('imports.statuses.'.$run->status),
            'errorCode' => $run->error_code,
            // A completed run never shows a stale failure reason.
            'errorLabel' => $run->status === ImportRun::STATUS_FAILED ? $this->errorLabel($run->error_code) : null,
            'isRunning' => $run->isRunning(),
            'isQueued' => $run->status === ImportRun::STATUS_QUEUED,
            'isStaged' => $run->status === ImportRun::STATUS_STAGED,
            'progressTotal' => $progressTotal,
            'progressDone' => $progressDone,
            'progressPercent' => $progressTotal > 0 ? (int) floor($progressDone / $progressTotal * 100) : 0,
            'queueWorkerRunning' => $this->queueWorkerRunning(),
            'cancelUrl' => route('imports.cancel', ['run' => (string) $run->id]),
            'counters' => [
                'vaults' => (int) ($counters['vaults'] ?? 0),
                'folders' => (int) ($counters['folders'] ?? 0),
                'tags' => (int) ($counters['tags'] ?? 0),
                'entries' => (int) ($counters['entries'] ?? 0),
                'grants' => (int) ($counters['grants'] ?? 0),
                'attachments' => (int) ($counters['attachments'] ?? 0),
            ],
            'summary' => (string) __('imports.report.summary', [
                'entries' => (int) ($counters['entries'] ?? 0),
                'vaults' => (int) ($counters['vaults'] ?? 0),
                'failures' => (int) $run->failure_count,
            ]),
            'attachmentSummary' => (int) ($counters['attachment_failures'] ?? 0) > 0
                ? (string) __('imports.report.attachment_summary', [
                    'attachments' => (int) ($counters['attachments'] ?? 0),
                    'missing' => (int) ($counters['attachment_failures'] ?? 0),
                ])
                : null,
            'contextLabel' => $this->contextLabel((string) $run->source_format),
            'failureCount' => (int) $run->failure_count,
            'showFailures' => $failures !== [],
            'failuresTruncated' => (int) $run->failure_count > count($failures),
            'failures' => array_map(fn (array $failure): array => $this->failureRow($failure), $failures),
            'vaults' => $this->vaultLinks($run),
            'isTerminal' => $run->isTerminal(),
            'reviewUrl' => ImportWizard::getUrl(['run' => (string) $run->id]),
        ];
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private function vaultLinks(ImportRun $run): array
    {
        $ids = is_array($run->created_vault_ids) ? $run->created_vault_ids : [];
        if ($ids === []) {
            return [];
        }

        $names = Vault::query()->whereIn('id', $ids)->pluck('name', 'id');

        $links = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            $links[] = [
                'name' => (string) ($names[$id] ?? __('imports.report.unknown_vault')),
                'url' => VaultEntries::getUrl(['vault' => $id]),
            ];
        }

        return $links;
    }

    /**
     * @param  array<string, mixed>  $failure
     * @return array{title: string, context: string, error: string, attachment: string|null, entry_url: string|null}
     */
    private function failureRow(array $failure): array
    {
        $isAttachment = ($failure['kind'] ?? 'entry') === 'attachment';
        $entryId = is_string($failure['entry_id'] ?? null) ? $failure['entry_id'] : null;

        return [
            'title' => (string) ($failure['title'] ?? ''),
            'context' => (string) ($failure['context'] ?? $failure['client'] ?? ''),
            'error' => (string) $this->errorLabel(is_string($failure['error_code'] ?? null) ? $failure['error_code'] : null),
            'attachment' => $isAttachment
                ? (string) __('imports.report.attachment_position', ['index' => (int) ($failure['index'] ?? 0), 'of' => (int) ($failure['of'] ?? 0)])
                : null,
            'entry_url' => $isAttachment && $entryId !== null ? EntryDetail::getUrl(['entry' => $entryId]) : null,
        ];
    }

    private function contextLabel(string $source): string
    {
        $key = 'imports.context_labels.'.$source;

        return trans()->has($key) ? (string) __($key) : (string) __('imports.fields.context');
    }

    private function queueWorkerRunning(): bool
    {
        return app(InstallationHealth::class)->queueWorkerRunning();
    }

    private function errorLabel(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $key = 'imports.failure_codes.'.$code;

        return trans()->has($key) ? (string) __($key) : $code;
    }

    private function runModel(): ImportRun
    {
        return $this->resolved ??= ImportRun::query()->findOrFail($this->run);
    }

    private function authorizePage(): void
    {
        abort_if($this->run === '', 404);
        Gate::authorize('manage-installation');
        $this->runModel();
    }
}
