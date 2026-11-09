<?php

namespace App\Filament\Pages;

use App\Models\Entry;
use App\Models\FileAttachment;
use App\Models\User;
use App\Services\EntryAttachments;
use App\Services\EntryPayload;
use App\Services\EntryVault;
use App\Services\Favorites;
use App\Services\SiteIcons\SiteIcons;
use App\Services\TrashLifecycle;
use App\Services\VaultAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

class EntryDetail extends Page
{
    protected string $view = 'filament.pages.entry-detail';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Vaults';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    #[Locked]
    #[Url(as: 'entry')]
    public string $entryId = '';

    private ?Entry $resolved = null;

    public function mount(): void
    {
        $this->authorizeEntry();
    }

    public function hydrate(): void
    {
        $this->resolved = null;
        $this->authorizeEntry();
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getTitle(): string
    {
        return $this->selectedEntry()->title;
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $actions = [
            Action::make('back')->label((string) __('entries.detail.action.entries'))->icon(Heroicon::OutlinedArrowLeft)
                ->url(fn (): string => VaultEntries::getUrl(['vault' => $this->selectedEntry()->vault_id])),
        ];

        if ($this->allows('entry.update')) {
            $actions[] = Action::make('edit')->label((string) __('entries.detail.action.edit'))->icon(Heroicon::OutlinedPencilSquare)
                ->url(fn (): string => EntryForm::getUrl([
                    'vault' => $this->selectedEntry()->vault_id,
                    'entry' => $this->selectedEntry()->id,
                ]));
        }

        if ($this->allows('entry.update') && $this->selectedEntry()->url_host !== null && app(SiteIcons::class)->enabled()) {
            $actions[] = Action::make('refreshIcon')->label((string) __('entries.detail.action.refresh_icon'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->action(function (): void {
                    abort_unless($this->allows('entry.update'), 403);
                    app(SiteIcons::class)->refresh((string) $this->selectedEntry()->url_host);
                    Notification::make()->success()->title((string) __('entries.detail.icon_refresh_queued'))->send();
                });
        }

        if ($this->allows('entry.move')) {
            $actions[] = Action::make('move')->label((string) __('entries.detail.action.move'))->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')
                ->url(fn (): string => EntryMove::getUrl(['entry' => $this->selectedEntry()->id]));
        }

        if (app(VaultAccess::class)->allows($this->user(), (string) $this->selectedEntry()->vault_id, 'grant.create')) {
            $actions[] = Action::make('access')->label((string) __('entries.detail.action.access'))->icon(Heroicon::OutlinedUserPlus)->color('gray')
                ->url(fn (): string => ScopedAccess::getUrl([
                    'vault' => $this->selectedEntry()->vault_id,
                    'kind' => 'entry',
                    'target' => $this->selectedEntry()->id,
                ]));
        }

        if ($this->allows('entry.delete')) {
            $actions[] = Action::make('delete')->label((string) __('entries.detail.action.delete'))->icon(Heroicon::OutlinedTrash)->color('danger')
                ->requiresConfirmation()
                ->modalDescription((string) __('entries.detail.delete_confirm'))
                ->action(function (): void {
                    try {
                        app(TrashLifecycle::class)->trashEntry($this->user(), $this->selectedEntry()->id);
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title((string) __('entries.detail.not_deleted'))
                            ->body((string) collect($exception->errors())->flatten()->first())->send();

                        return;
                    }

                    Notification::make()->success()->title((string) __('entries.detail.deleted'))->send();
                    $this->redirect(VaultEntries::getUrl(['vault' => $this->selectedEntry()->vault_id]));
                });
        }

        $actions[] = Action::make('favorite')
            ->label(fn (): string => $this->isFavorite() ? (string) __('favorites.actions.remove_entry') : (string) __('favorites.actions.add_entry'))
            ->icon(fn (): Heroicon => $this->isFavorite() ? Heroicon::Star : Heroicon::OutlinedStar)
            ->color(fn (): string => $this->isFavorite() ? 'warning' : 'gray')
            ->iconButton()
            ->tooltip(fn (): string => $this->isFavorite() ? (string) __('favorites.actions.remove_entry') : (string) __('favorites.actions.add_entry'))
            ->action(function (): void {
                $state = app(Favorites::class)->toggleEntry($this->user(), $this->selectedEntry()->id);
                Notification::make()->success()->title(
                    $state ? (string) __('favorites.notifications.entry_added') : (string) __('favorites.notifications.entry_removed')
                )->send();
            });

        return $actions;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $entry = $this->selectedEntry();
        $canRestore = $this->allows('entry.restore');
        $canReveal = $this->allows('secret.reveal');
        $payloads = app(EntryPayload::class);
        $vault = app(EntryVault::class);

        return [
            'entry' => $entry,
            'typeLabel' => EntryPayload::typeLabels()[(string) $entry->type] ?? (string) $entry->type,
            'typeIcon' => $this->typeIcon((string) $entry->type),
            'expiryState' => $this->expiryState($entry),
            'valueRows' => $this->valueRows($entry, $canReveal, $payloads, $vault),
            'canReveal' => $canReveal,
            'canEdit' => $this->allows('entry.update'),
            'canRestore' => $canRestore,
            'showTotp' => $vault->totpAvailable($this->user(), $entry),
            'history' => $canRestore ? $vault->history($this->user(), $entry->id) : new Collection,
            'canManageAttachments' => $this->allows('entry.update'),
            'attachmentRows' => $this->attachmentRows($entry),
            'attachmentUsage' => $this->attachmentUsage($entry),
        ];
    }

    /**
     * @return array{used: string, limit: string, per_file: string}
     */
    private function attachmentUsage(Entry $entry): array
    {
        $usage = app(EntryAttachments::class)->usage((string) $entry->vault_id);

        return [
            'used' => $this->formatBytes($usage['used']),
            'limit' => $this->formatBytes($usage['limit']),
            'per_file' => $this->formatBytes($usage['per_file']),
        ];
    }

    /**
     * The authorized attachment list. Filenames and detected MIME types are
     * decrypted here for the authorized detail view only; they are never part
     * of a list projection.
     *
     * @return list<array{id: string, filename: string, mime: string, size: string, uploadedBy: string, uploadedAt: string, downloadUrl: string}>
     */
    private function attachmentRows(Entry $entry): array
    {
        $attachments = app(EntryAttachments::class);
        $rows = [];

        foreach ($attachments->forEntry($entry) as $attachment) {
            /** @var FileAttachment $attachment */
            $metadata = $attachments->metadata($attachment);
            $rows[] = [
                'id' => (string) $attachment->id,
                'filename' => $metadata['filename'],
                'mime' => $metadata['mime'],
                'size' => $this->formatBytes((int) $attachment->byte_size),
                'uploadedBy' => $attachment->createdBy->name,
                'uploadedAt' => $attachment->created_at?->toDayDateTimeString() ?? '—',
                'downloadUrl' => route('attachments.download', ['attachment' => $attachment->id]),
            ];
        }

        return $rows;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return (string) __('attachments.sizes.megabytes', ['count' => number_format($bytes / 1048576, 1)]);
        }

        if ($bytes >= 1024) {
            return (string) __('attachments.sizes.kilobytes', ['count' => number_format($bytes / 1024, 1)]);
        }

        return (string) __('attachments.sizes.bytes', ['count' => $bytes]);
    }

    private function typeIcon(string $type): string
    {
        return match ($type) {
            EntryPayload::TYPE_NOTE => 'heroicon-o-document-text',
            EntryPayload::TYPE_SSH_KEY => 'heroicon-o-command-line',
            EntryPayload::TYPE_TOTP => 'heroicon-o-shield-check',
            default => 'heroicon-o-key',
        };
    }

    /**
     * How urgently the expiry date should be presented: no expiry, already
     * expired, expiring within a month, or comfortably in the future.
     */
    private function expiryState(Entry $entry): string
    {
        $expiresAt = $entry->expires_at;
        if ($expiresAt === null) {
            return 'none';
        }

        if ($expiresAt->isPast()) {
            return 'expired';
        }

        return $expiresAt->lessThanOrEqualTo(now()->addDays(30)) ? 'soon' : 'ok';
    }

    /**
     * The revealable values and custom fields as one normalized list, so the
     * view can render a single loop with consistent reveal/copy controls.
     *
     * @return list<array{key: string, label: string, multiline: bool, concealable: bool, revealUrl: string|null, copyValue: string|null, badge: string|null}>
     */
    private function valueRows(Entry $entry, bool $canReveal, EntryPayload $payloads, EntryVault $vault): array
    {
        $rows = [];

        foreach ($payloads->revealableFields((string) $entry->type) as $key) {
            $definition = $payloads->fields((string) $entry->type)[$key];
            $rows[] = [
                'key' => $key,
                'label' => $definition['label'],
                'multiline' => (bool) ($definition['multiline'] ?? false),
                'concealable' => true,
                'revealUrl' => $canReveal ? route('entries.reveal', ['entry' => $entry->id, 'field' => $key]) : null,
                'copyValue' => null,
                'badge' => null,
            ];
        }

        foreach ($vault->customFieldsForDisplay($entry) as $custom) {
            $concealed = (bool) $custom['concealed'];
            $rows[] = [
                'key' => 'custom-'.$custom['index'],
                'label' => $custom['label'] !== '' ? $custom['label'] : (string) __('entries.detail.custom_field'),
                'multiline' => false,
                'concealable' => $concealed,
                'revealUrl' => $concealed && $canReveal
                    ? route('entries.reveal_custom', ['entry' => $entry->id, 'index' => $custom['index']])
                    : null,
                'copyValue' => $concealed ? null : $custom['value'],
                'badge' => $concealed ? (string) __('entries.detail.concealed') : null,
            ];
        }

        return $rows;
    }

    public function restore(string $revisionId): void
    {
        app(EntryVault::class)->restore($this->user(), $this->selectedEntry()->id, $revisionId);
        Notification::make()->success()->title((string) __('entries.detail.restored.title'))
            ->body((string) __('entries.detail.restored.body'))->send();
    }

    private function authorizeEntry(): void
    {
        abort_if($this->entryId === '', 404);
        $this->selectedEntry();
    }

    private function selectedEntry(): Entry
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        return $this->resolved = app(EntryVault::class)->find($this->user(), $this->entryId);
    }

    private function allows(string $ability): bool
    {
        return app(VaultAccess::class)->allowsOnEntry($this->user(), $this->selectedEntry(), $ability);
    }

    private function isFavorite(): bool
    {
        return app(Favorites::class)->isEntryFavorite($this->user(), $this->selectedEntry()->id);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
