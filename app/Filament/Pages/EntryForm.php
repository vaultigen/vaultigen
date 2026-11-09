<?php

namespace App\Filament\Pages;

use App\Models\Entry;
use App\Models\User;
use App\Models\Vault;
use App\Services\EntryPayload;
use App\Services\EntryVault;
use App\Services\VaultAccess;
use App\Services\VaultDirectory;
use App\Services\VaultFolders;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The entry create/edit screen.
 *
 * Secrets are intentionally kept out of Livewire state: the screen is a plain
 * HTTP POST form and only non-secret metadata is bound to Filament fields. The
 * Livewire-bound values are mirrored into the POST through Alpine hidden
 * inputs so the secret inputs can still be submitted directly.
 *
 * @property-read Schema $form
 */
class EntryForm extends Page
{
    protected string $view = 'filament.pages.entry-form';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Vaults';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    /** @var array<string, mixed> */
    public array $data = [];

    #[Locked]
    #[Url(as: 'vault')]
    public string $vaultId = '';

    #[Locked]
    #[Url(as: 'entry')]
    public ?string $entryId = null;

    #[Locked]
    #[Url(as: 'folder')]
    public ?string $folderId = null;

    #[Locked]
    #[Url(as: 'type')]
    public ?string $entryType = null;

    private ?Vault $vaultModel = null;

    private ?Entry $entryModel = null;

    public function mount(): void
    {
        $this->authorizePage();

        $totp = $this->totpParameters();

        $this->form->fill([
            'type' => $this->resolvedType(),
            'owner_user_id' => $this->initialOwnerId(),
            'expires_at' => $this->initialExpiresAt(),
            'generated' => filter_var(old('generated', '0'), FILTER_VALIDATE_BOOLEAN),
            'folder_id' => $this->initialFolderId(),
            'new_folder_name' => old('new_folder_name'),
            'algorithm' => $this->stringOld('algorithm', $totp['algorithm']),
            'digits' => $this->stringOld('digits', (string) $totp['digits']),
            'period' => $this->stringOld('period', (string) $totp['period']),
            'tags' => $this->initialTagNames(),
        ]);
    }

    public function hydrate(): void
    {
        $this->vaultModel = null;
        $this->entryModel = null;
        $this->authorizePage();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make((string) __('entries.form.section.details'))
                ->columns(2)
                ->schema([
                    ToggleButtons::make('type')
                        ->label((string) __('entries.form.entry_type.label'))
                        ->options(EntryPayload::typeLabels())
                        ->inline()
                        ->required()
                        ->columnSpanFull()
                        ->visible(fn (): bool => $this->entryModel === null)
                        ->helperText((string) __('entries.form.entry_type.helper')),
                    Select::make('owner_user_id')
                        ->label((string) __('entries.common.owner'))
                        ->options(fn (): array => $this->ownerOptions())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText((string) __('entries.form.owner.helper')),
                    DateTimePicker::make('expires_at')
                        ->label((string) __('entries.form.expires_at.label'))
                        ->seconds(false),
                ]),
            Section::make((string) __('entries.form.section.organization'))
                ->description((string) __('entries.form.organization.description'))
                ->columns(2)
                ->schema([
                    Select::make('folder_id')
                        ->label((string) __('entries.common.folder'))
                        ->options(fn (): array => $this->folderFieldOptions())
                        ->searchable()
                        ->preload()
                        ->required(fn (): bool => ! $this->hasVaultScope())
                        ->helperText(fn (): string => $this->hasVaultScope()
                            ? (string) __('entries.form.folder.helper_scoped')
                            : (string) __('entries.form.folder.helper_unscoped')),
                    TextInput::make('new_folder_name')
                        ->label((string) __('entries.form.new_folder.label'))
                        ->maxLength(VaultFolders::MAX_NAME)
                        ->visible(fn (): bool => $this->canCreateFolder())
                        ->helperText((string) __('entries.form.new_folder.helper')),
                    TagsInput::make('tags')
                        ->label((string) __('entries.common.tags'))
                        ->placeholder((string) __('entries.form.tags.placeholder'))
                        ->reorderable()
                        ->suggestions(fn (): array => $this->tagSuggestions())
                        ->visible(fn (): bool => $this->hasVaultScope())
                        ->columnSpanFull()
                        ->helperText((string) __('entries.form.tags.helper')),
                ]),
            Section::make((string) __('entries.form.section.totp'))
                ->description((string) __('entries.form.totp.description'))
                ->columns(3)
                ->extraAlpineAttributes(fn (): array => $this->clientVisibility(EntryPayload::TYPE_TOTP))
                ->schema([
                    Select::make('algorithm')
                        ->label((string) __('entries.form.algorithm.label'))
                        ->options(fn (): array => $this->totpAlgorithmOptions()),
                    Select::make('digits')
                        ->label((string) __('entries.form.digits.label'))
                        ->options(fn (): array => $this->totpDigitOptions()),
                    Select::make('period')
                        ->label((string) __('entries.form.period.label'))
                        ->options(fn (): array => $this->totpPeriodOptions()),
                ]),
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getTitle(): string
    {
        if ($this->entryModel !== null) {
            return (string) __('entries.form.title.edit', ['type' => EntryPayload::typeLabels()[(string) $this->entryModel->type]]);
        }

        return (string) __('entries.form.title.new', ['type' => EntryPayload::typeLabels()[$this->resolvedType()]]);
    }

    /**
     * Alpine attributes that hide a component until the selected entry type
     * matches. Keeping this client-side avoids a Livewire round trip over the
     * plain secret form.
     *
     * @return array<string, mixed>
     */
    private function clientVisibility(string $type): array
    {
        return [
            'x-show' => '$wire.data.type === \''.$type.'\'',
            'x-cloak' => true,
        ];
    }

    /**
     * @return list<string>
     */
    private function initialTagNames(): array
    {
        $old = old('tags');
        $names = is_array($old) ? $old : ($this->entryModel?->tags->pluck('name')->all() ?? []);

        return array_values(array_filter($names, 'is_string'));
    }

    private function initialOwnerId(): ?string
    {
        if (session()->hasOldInput('owner_user_id')) {
            $old = old('owner_user_id');
            if (is_string($old) && $old !== '') {
                return $old;
            }
        } elseif ($this->entryModel !== null) {
            return (string) $this->entryModel->owner_user_id;
        }

        $options = $this->ownerOptions();
        $userId = (string) $this->user()->id;
        if (array_key_exists($userId, $options)) {
            return $userId;
        }

        $first = array_key_first($options);

        return is_string($first) ? $first : null;
    }

    private function initialExpiresAt(): ?string
    {
        if (session()->hasOldInput('expires_at')) {
            $old = old('expires_at');

            return is_string($old) && $old !== '' ? $old : null;
        }

        return $this->entryModel?->expires_at?->format('Y-m-d H:i');
    }

    /**
     * The root folder is represented by an empty string so the hidden POST
     * bridge always submits a `folder_id` key, even when no folder is chosen.
     */
    private function initialFolderId(): string
    {
        if (session()->hasOldInput('folder_id')) {
            $old = old('folder_id');

            return is_string($old) ? $old : '';
        }

        if ($this->entryModel !== null) {
            return (string) ($this->entryModel->folder_id ?? '');
        }

        return (string) ($this->defaultFolderId($this->user(), $this->vault()) ?? '');
    }

    private function stringOld(string $key, string $default): string
    {
        $value = old($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    private function tagSuggestions(): array
    {
        return app(VaultDirectory::class)->tagSuggestions($this->user(), $this->vault()->id);
    }

    /**
     * @return array<string, string>
     */
    private function ownerOptions(): array
    {
        return app(EntryVault::class)->ownerOptions($this->vault());
    }

    /**
     * @return array<string, string>
     */
    private function folderOptions(): array
    {
        return app(VaultFolders::class)->options($this->user(), $this->vault()->id);
    }

    /**
     * @return array<string, string>
     */
    private function folderFieldOptions(): array
    {
        $options = $this->folderOptions();
        if ($this->canPlaceAtRoot()) {
            $options = ['' => (string) __('entries.form.folder.root_option')] + $options;
        }

        return $options;
    }

    private function hasVaultScope(): bool
    {
        return app(VaultAccess::class)->hasVaultScope($this->user(), $this->vault()->id);
    }

    private function canCreateFolder(): bool
    {
        return app(VaultAccess::class)->allows($this->user(), $this->vault()->id, 'folder.create');
    }

    private function canPlaceAtRoot(): bool
    {
        return app(VaultAccess::class)->canCreateEntry($this->user(), $this->vault()->id, null);
    }

    /**
     * @return array{algorithm: string, digits: int, period: int}
     */
    private function totpParameters(): array
    {
        $entry = $this->entryModel;
        if ($entry !== null && $this->resolvedType() === EntryPayload::TYPE_TOTP) {
            return app(EntryVault::class)->totpParametersForEdit($entry);
        }

        return [
            'algorithm' => EntryPayload::DEFAULT_TOTP_ALGORITHM,
            'digits' => EntryPayload::DEFAULT_TOTP_DIGITS,
            'period' => EntryPayload::DEFAULT_TOTP_PERIOD,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function totpAlgorithmOptions(): array
    {
        return array_combine(EntryPayload::TOTP_ALGORITHMS, EntryPayload::TOTP_ALGORITHMS);
    }

    /**
     * @return array<int|string, string>
     */
    private function totpDigitOptions(): array
    {
        return $this->intOptionMap(EntryPayload::TOTP_DIGITS);
    }

    /**
     * @return array<int|string, string>
     */
    private function totpPeriodOptions(): array
    {
        return $this->intOptionMap(EntryPayload::TOTP_PERIODS);
    }

    /**
     * @param  list<int>  $values
     * @return array<int|string, string>
     */
    private function intOptionMap(array $values): array
    {
        $options = [];
        foreach ($values as $value) {
            $options[(string) $value] = (string) $value;
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $vault = $this->vault();
        $user = $this->user();
        $entry = $this->entryModel;
        $type = $this->resolvedType();
        $entries = app(EntryVault::class);

        return [
            'vault' => $vault,
            'entry' => $entry,
            'type' => $type,
            'isEdit' => $entry !== null,
            'typeLabels' => EntryPayload::typeLabels(),
            'totpAlgorithms' => EntryPayload::TOTP_ALGORITHMS,
            'totpDigits' => EntryPayload::TOTP_DIGITS,
            'totpPeriods' => EntryPayload::TOTP_PERIODS,
            'totpConfig' => $this->totpParameters(),
            'hasTotpSeed' => $entry !== null && $entries->hasTotpSeed($entry),
            'customFields' => $entry === null ? [] : $entries->customFieldRowsForEdit($entry),
            'ownerOptions' => $this->ownerOptions(),
            'lockVersion' => $entry === null ? 0 : $entry->lock_version,
            'folderOptions' => $this->folderOptions(),
            'tagSuggestions' => $this->tagSuggestions(),
            'vaultScope' => $this->hasVaultScope(),
            'canCreateFolder' => $this->canCreateFolder(),
            'selectedFolderId' => $entry === null ? $this->defaultFolderId($user, $vault) : $entry->folder_id,
            'canPlaceAtRoot' => $this->canPlaceAtRoot(),
        ];
    }

    private function resolvedType(): string
    {
        $current = $this->data['type'] ?? null;
        if (is_string($current) && in_array($current, EntryPayload::TYPES, true)) {
            return $current;
        }

        if ($this->entryModel !== null) {
            return (string) $this->entryModel->type;
        }

        $old = old('type');
        if (is_string($old) && in_array($old, EntryPayload::TYPES, true)) {
            return $old;
        }

        return is_string($this->entryType) && in_array($this->entryType, EntryPayload::TYPES, true)
            ? $this->entryType
            : EntryPayload::TYPE_LOGIN;
    }

    private function defaultFolderId(User $user, Vault $vault): ?string
    {
        if ($this->folderId === null || $this->folderId === '') {
            return null;
        }

        return array_key_exists($this->folderId, $this->folderOptions())
            ? $this->folderId
            : null;
    }

    private function authorizePage(): void
    {
        abort_if($this->vaultId === '', 404);

        $user = $this->user();
        $vault = app(VaultAccess::class)->visibleVaults($user)->find($this->vaultId);
        abort_if($vault === null, 404);
        $this->vaultModel = $vault;

        if ($this->entryId === null || $this->entryId === '') {
            abort_unless(app(VaultAccess::class)->canCreateInVault($user, $vault->id), 403);

            return;
        }

        $entry = app(EntryVault::class)->find($user, $this->entryId);
        abort_unless($entry->vault_id === $vault->id, 404);
        abort_unless(app(VaultAccess::class)->allowsOnEntry($user, $entry, 'entry.update'), 403);
        $this->entryModel = $entry;
    }

    private function vault(): Vault
    {
        /** @var Vault */
        return $this->vaultModel;
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
