<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Models\Vault;
use App\Services\InstallationSettings;
use App\Services\VaultAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Manager-only trash for one vault. Deleted entries and folders stay visible
 * with their deletion date and purge deadline until the retention-bound purge
 * job removes them.
 */
class Trash extends Page
{
    protected string $view = 'filament.pages.trash';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Vaults';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    #[Locked]
    #[Url(as: 'vault')]
    public string $vault = '';

    private ?Vault $resolved = null;

    public function mount(): void
    {
        $this->authorizeManager();
    }

    public function hydrate(): void
    {
        $this->resolved = null;
        $this->authorizeManager();
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getTitle(): string
    {
        return (string) __('trash.title', ['vault' => $this->selectedVault()->name]);
    }

    public function getSubheading(): ?string
    {
        return (string) __('trash.subheading');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label((string) __('entries.detail.action.entries'))->icon(Heroicon::OutlinedArrowLeft)->color('gray')
                ->url(fn (): string => VaultEntries::getUrl(['vault' => $this->vault])),
            Action::make('folders')->label((string) __('entries.list.action.manage_folders'))->icon(Heroicon::OutlinedFolderOpen)
                ->url(fn (): string => ManageFolders::getUrl(['vault' => $this->vault])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'vaultId' => $this->selectedVault()->id,
            'retentionDays' => app(InstallationSettings::class)->trashRetentionDays(),
        ];
    }

    private function authorizeManager(): void
    {
        abort_if($this->vault === '', 404);
        abort_unless(app(VaultAccess::class)->allows($this->user(), $this->vault, 'entry.delete'), 404);
    }

    private function selectedVault(): Vault
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $vault = app(VaultAccess::class)->visibleVaults($this->user())->find($this->vault);
        abort_if($vault === null, 404);

        return $this->resolved = $vault;
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
