<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Models\Vault;
use App\Services\Offboarding as OffboardingService;
use BackedEnum;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Super-Admin offboarding. Deactivates an account, invalidates its sessions, and
 * forces an explicit transfer/archive/purge decision for every personal work
 * vault it owns and every shared vault it is Custodian of. It also lists the
 * credentials the account revealed so they can be rotated.
 *
 * @property-read Schema $form
 */
class Offboarding extends Page
{
    protected string $view = 'filament.pages.offboarding';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserMinus;

    protected static ?int $navigationSort = 20;

    /** @var array<string, mixed> */
    public array $data = [];

    /** The account to preselect when the page is opened from another screen. */
    #[Locked]
    #[Url(as: 'user')]
    public ?string $user = null;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-installation');
    }

    public function getSubheading(): string
    {
        return (string) __('offboarding.subheading');
    }

    public function mount(): void
    {
        Gate::authorize('manage-installation');

        $userId = $this->requestedUserId();
        $this->form->fill([
            'user_id' => $userId,
            'personal' => $this->personalDefaults($userId),
            'custodian' => $this->custodianDefaults($userId),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make((string) __('offboarding.sections.account'))
                ->description((string) __('offboarding.sections.account_description'))
                ->icon(Heroicon::OutlinedUserMinus)
                ->schema([
                    Select::make('user_id')
                        ->label((string) __('offboarding.fields.user'))
                        ->options(fn (): array => $this->userOptions())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $set('personal', $this->personalDefaults($state));
                            $set('custodian', $this->custodianDefaults($state));
                        }),
                ]),
            Section::make((string) __('offboarding.sections.personal'))
                ->description((string) __('offboarding.sections.personal_description'))
                ->icon(Heroicon::OutlinedBriefcase)
                ->visible(fn (): bool => filled($this->data['user_id'] ?? null))
                ->schema([
                    Repeater::make('personal')
                        ->hiddenLabel()
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->schema([
                            Hidden::make('vault_id'),
                            TextInput::make('name')->label((string) __('offboarding.fields.vault'))->disabled()->dehydrated(false),
                            Select::make('decision')
                                ->label((string) __('offboarding.fields.decision'))
                                ->options([
                                    'convert' => (string) __('offboarding.decisions.convert'),
                                    'archive' => (string) __('offboarding.decisions.archive'),
                                    'purge' => (string) __('offboarding.decisions.purge'),
                                ])
                                ->live()
                                ->required(),
                            Select::make('custodian_user_id')
                                ->label((string) __('offboarding.fields.new_custodian'))
                                ->options(fn (): array => $this->custodianOptions())
                                ->searchable()
                                ->visible(fn (Get $get): bool => $get('decision') === 'convert')
                                ->required(fn (Get $get): bool => $get('decision') === 'convert'),
                            Select::make('manager_user_id')
                                ->label((string) __('offboarding.fields.new_manager'))
                                ->options(fn (): array => $this->custodianOptions())
                                ->searchable()
                                ->visible(fn (Get $get): bool => $get('decision') === 'convert')
                                ->required(fn (Get $get): bool => $get('decision') === 'convert'),
                        ])
                        ->columns(2),
                ]),
            Section::make((string) __('offboarding.sections.custodian'))
                ->description((string) __('offboarding.sections.custodian_description'))
                ->icon(Heroicon::OutlinedShieldCheck)
                ->visible(fn (): bool => filled($this->data['user_id'] ?? null))
                ->schema([
                    Repeater::make('custodian')
                        ->hiddenLabel()
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->schema([
                            Hidden::make('vault_id'),
                            TextInput::make('name')->label((string) __('offboarding.fields.vault'))->disabled()->dehydrated(false),
                            Select::make('transfer_user_id')
                                ->label((string) __('offboarding.fields.new_custodian'))
                                ->options(fn (): array => $this->custodianOptions())
                                ->searchable()
                                ->required(),
                        ])
                        ->columns(2),
                ]),
        ]);
    }

    public function offboard(): void
    {
        Gate::authorize('manage-installation');

        /** @var User $actor */
        $actor = auth()->user();

        try {
            $data = $this->form->getState();
            $userId = is_string($data['user_id'] ?? null) ? $data['user_id'] : null;
            $target = $userId === null ? null : User::query()->whereKey($userId)->first();
            if ($target === null) {
                throw ValidationException::withMessages(['user_id' => __('offboarding.errors.user_required')]);
            }

            app(OffboardingService::class)->offboard($actor, $target, $data);
            Notification::make()->success()->title((string) __('offboarding.notifications.offboarded', ['name' => $target->name]))->send();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title((string) __('offboarding.notifications.not_offboarded'))
                ->body((string) collect($exception->errors())->flatten()->first())->send();

            return;
        }

        $this->form->fill(['user_id' => null, 'personal' => [], 'custodian' => []]);
    }

    /**
     * @return array{target: User|null, personalVaults: EloquentCollection<int, Vault>, custodianVaults: EloquentCollection<int, Vault>}
     */
    protected function getViewData(): array
    {
        $target = $this->target();
        $repository = app(OffboardingService::class);
        $none = fn (): EloquentCollection => Vault::query()->whereRaw('1 = 0')->get();

        return [
            'target' => $target,
            'personalVaults' => $target === null ? $none() : $repository->personalVaults($target),
            'custodianVaults' => $target === null ? $none() : $repository->custodianVaults($target),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function userOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereKeyNot(auth()->id())
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} ({$user->email})"])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function custodianOptions(): array
    {
        $query = User::query()->where('is_active', true);
        $targetId = $this->target()?->id;
        if ($targetId !== null) {
            $query->whereKeyNot($targetId);
        }

        return $query
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} ({$user->email})"])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function personalDefaults(?string $userId): array
    {
        $target = $this->resolveTarget($userId);

        return $target === null ? [] : array_values(app(OffboardingService::class)->personalVaults($target)
            ->map(fn (Vault $vault): array => [
                'vault_id' => $vault->id,
                'name' => $vault->name,
                'decision' => 'archive',
                'custodian_user_id' => null,
                'manager_user_id' => null,
            ])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function custodianDefaults(?string $userId): array
    {
        $target = $this->resolveTarget($userId);

        return $target === null ? [] : array_values(app(OffboardingService::class)->custodianVaults($target)
            ->map(fn (Vault $vault): array => [
                'vault_id' => $vault->id,
                'name' => $vault->name,
                'transfer_user_id' => null,
            ])
            ->all());
    }

    /**
     * The account to preselect when the page is opened from another screen, for
     * example the offboard action in the user table. Only an active account other
     * than the signed-in administrator is accepted.
     */
    private function requestedUserId(): ?string
    {
        $userId = $this->user;
        if ($userId === null || $userId === '') {
            return null;
        }

        $exists = User::query()
            ->whereKey($userId)
            ->where('is_active', true)
            ->whereKeyNot(auth()->id())
            ->exists();

        return $exists ? $userId : null;
    }

    private function target(): ?User
    {
        return $this->resolveTarget(is_string($this->data['user_id'] ?? null) ? $this->data['user_id'] : null);
    }

    private function resolveTarget(?string $userId): ?User
    {
        return $userId === null || $userId === '' ? null : User::query()->find($userId);
    }
}
