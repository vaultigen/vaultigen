<?php

namespace App\Filament\Pages;

use App\Models\Entry;
use App\Models\User;
use App\Services\EntryVault;
use App\Services\VaultAccess;
use App\Services\VaultFolders;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Move one entry to another folder or another vault.
 *
 * Destination authorization is enforced by the domain service, not the form.
 * A cross-vault move re-encrypts the entry and its history under the
 * destination key and drops vault-scoped tag labels.
 *
 * @property-read Schema $form
 */
class EntryMove extends Page
{
    protected string $view = 'filament.pages.entry-move';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Vaults';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    #[Locked]
    #[Url(as: 'entry')]
    public string $entryId = '';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $entry = $this->authorizeMove();
        $this->form->fill([
            'destination_vault_id' => $entry->vault_id,
            'destination_folder_id' => $entry->folder_id,
            'new_folder_name' => null,
        ]);
    }

    public function hydrate(): void
    {
        $this->authorizeMove();
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getTitle(): string
    {
        return (string) __('entries.move.title');
    }

    public function getSubheading(): ?string
    {
        return (string) __('entries.move.subheading');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make((string) __('entries.move.section.destination'))
                ->description((string) __('entries.move.destination.description'))
                ->schema([
                    Select::make('destination_vault_id')
                        ->label((string) __('entries.move.vault.label'))
                        ->options(fn (): array => $this->vaultOptions())
                        ->required()
                        ->live()
                        ->helperText((string) __('entries.move.vault.helper')),
                    Select::make('destination_folder_id')
                        ->label((string) __('entries.common.folder'))
                        ->options(fn (Get $get): array => $this->folderOptions((string) $get('destination_vault_id')))
                        ->searchable()
                        ->live()
                        ->placeholder((string) __('entries.common.no_folder')),
                    TextInput::make('new_folder_name')
                        ->label((string) __('entries.move.new_folder.label'))
                        ->maxLength(VaultFolders::MAX_NAME)
                        ->visible(fn (Get $get): bool => app(VaultAccess::class)->allows($this->user(), (string) $get('destination_vault_id'), 'folder.create'))
                        ->helperText((string) __('entries.move.new_folder.helper')),
                ]),
            Section::make((string) __('entries.move.section.access'))
                ->schema([
                    Placeholder::make('impact')->hiddenLabel()->content(fn (): HtmlString => new HtmlString(
                        '<ul class="list-disc space-y-1 ps-5 text-sm text-gray-600 dark:text-gray-300">'
                        .'<li>'.__('entries.move.impact.destination').'</li>'
                        .'<li>'.__('entries.move.impact.lose_access').'</li>'
                        .'<li>'.__('entries.move.impact.cross_vault').'</li>'
                        .'</ul>'
                    )),
                ]),
        ]);
    }

    public function move(): void
    {
        $this->authorizeMove();
        $data = $this->form->getState();
        $folderId = $data['destination_folder_id'] === null || $data['destination_folder_id'] === '' ? null : (string) $data['destination_folder_id'];
        $newFolder = $data['new_folder_name'] === null || $data['new_folder_name'] === '' ? null : (string) $data['new_folder_name'];

        try {
            app(EntryVault::class)->move($this->user(), $this->entryId, (string) $data['destination_vault_id'], $folderId, $newFolder);
        } catch (AuthorizationException $exception) {
            Notification::make()->danger()->title((string) __('entries.move.not_moved'))->body($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title((string) __('entries.move.moved'))->send();
        $this->redirect(EntryDetail::getUrl(['entry' => $this->entryId]));
    }

    /**
     * @return array<string, string>
     */
    private function vaultOptions(): array
    {
        $access = app(VaultAccess::class);
        $options = [];
        foreach ($access->visibleVaults($this->user())->where('state', 'active')->orderBy('name')->get() as $vault) {
            if ($access->canCreateInVault($this->user(), (string) $vault->id)) {
                $options[(string) $vault->id] = $vault->name;
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function folderOptions(string $vaultId): array
    {
        if ($vaultId === '') {
            return [];
        }

        $access = app(VaultAccess::class);
        $options = [];
        if ($access->canCreateEntry($this->user(), $vaultId, null)) {
            $options[''] = (string) __('entries.common.no_folder');
        }
        foreach ($access->visibleFolders($this->user(), $vaultId)->orderBy('name')->get() as $folder) {
            if ($access->allowsOnFolder($this->user(), (string) $folder->id, 'entry.create')) {
                $options[(string) $folder->id] = $folder->name;
            }
        }

        return $options;
    }

    private function authorizeMove(): Entry
    {
        abort_if($this->entryId === '', 404);
        $entry = app(EntryVault::class)->find($this->user(), $this->entryId);
        abort_unless(app(VaultAccess::class)->allowsOnEntry($this->user(), $entry, 'entry.move'), 404);

        return $entry;
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
