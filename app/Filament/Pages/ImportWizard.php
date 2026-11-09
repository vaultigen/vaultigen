<?php

namespace App\Filament\Pages;

use App\Contracts\ImportDriver;
use App\Filament\Livewire\ImportTagsTable;
use App\Filament\Livewire\ImportVaultsTable;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\Imports\ImportPlanEditor;
use App\Services\Imports\ImportPlanner;
use App\Services\PrincipalDirectory;
use App\Services\VaultMembership;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The source-agnostic import wizard.
 *
 * The first step combines the export summary, the layout/axes configuration,
 * and the extra vault access; the remaining steps review the vaults and tags.
 * Axes, layouts, summary chips, and the confirm step's credential fields all
 * come from the run's driver. All non-secret state is Livewire state. Any
 * execution credential (such as the sysPass master password) is deliberately
 * not: the confirm step is a plain HTTP POST form, so the secret never enters a
 * Livewire property or snapshot.
 *
 * @property-read Schema $form
 */
class ImportWizard extends Page
{
    protected string $view = 'filament.pages.import-wizard';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    #[Locked]
    #[Url(as: 'run')]
    public string $run = '';

    /** @var array<string, mixed> */
    public array $data = [];

    public bool $planSaved = false;

    /** @var array<string, mixed> */
    public array $preview = [];

    private ?ImportRun $resolved = null;

    public function mount(): void
    {
        $this->authorizePage();

        if ($this->runModel()->isTerminal()) {
            $this->redirect(ImportReport::getUrl(['run' => $this->run]));

            return;
        }

        $plan = is_array($this->runModel()->plan) ? $this->runModel()->plan : $this->planEditor()->defaultPlan($this->runModel());
        $this->preview = $this->driver()->preview($this->runModel());
        $this->data = $this->planToFormState($plan);

        if (! is_array($this->runModel()->plan)) {
            // Persist the default plan immediately so the confirm step always
            // executes a complete plan even if the admin changes nothing.
            $this->planEditor()->applyConfigAndGrants($this->runModel(), $this->configInput(), []);
        }

        $this->planSaved = true;
        $this->form->fill($this->data);
    }

    public function hydrate(): void
    {
        $this->resolved = null;
        $this->authorizePage();
    }

    public static function canAccess(): bool
    {
        return Gate::allows('manage-installation');
    }

    public function getTitle(): string
    {
        return (string) __('imports.wizard.title');
    }

    public function getSubheading(): string
    {
        return (string) __('imports.wizard.subheading');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Wizard::make([
                Step::make((string) __('imports.wizard.steps.setup'))
                    ->schema([
                        $this->stepIntro('setup'),
                        Section::make((string) __('imports.wizard.sections.summary'))
                            ->description((string) __('imports.wizard.sections.summary_description'))
                            ->icon(Heroicon::OutlinedDocumentChartBar)
                            ->schema($this->summarySchema()),
                        Section::make((string) __('imports.wizard.sections.layout'))
                            ->description((string) __('imports.wizard.sections.layout_description'))
                            ->icon(Heroicon::OutlinedSquares2x2)
                            ->columns(2)
                            ->schema($this->layoutSchema()),
                        Section::make((string) __('imports.wizard.sections.access'))
                            ->description((string) __('imports.wizard.sections.access_description'))
                            ->icon(Heroicon::OutlinedUsers)
                            ->columns(2)
                            ->schema($this->accessSchema()),
                    ]),
                Step::make((string) __('imports.wizard.steps.vaults'))
                    ->schema([
                        $this->stepIntro('vaults'),
                        ...$this->vaultsSchema(),
                    ]),
                Step::make((string) __('imports.wizard.steps.tags'))
                    ->schema([
                        $this->stepIntro('tags'),
                        ...$this->tagsSchema(),
                    ]),
                Step::make((string) __('imports.wizard.steps.confirm'))
                    ->schema([
                        $this->stepIntro('confirm'),
                        Section::make((string) __('imports.confirm.heading'))
                            ->description((string) __('imports.confirm.description'))
                            ->icon(Heroicon::OutlinedLockClosed)
                            ->schema([
                                Html::make(fn (): string => view('filament.pages.partials.import-confirm', [
                                    'fields' => $this->driver()->executionCredentialFields(),
                                ])->render()),
                            ]),
                    ]),
            ])
                ->submitAction(new HtmlString(
                    view('filament.pages.partials.import-confirm-submit')->render(),
                )),
        ]);
    }

    /**
     * Persist the reviewed plan. Called by the wizard's form submit.
     */
    public function save(): void
    {
        $this->authorizePage();
        $this->normalizeAxes();

        try {
            $plan = $this->planEditor()->applyConfigAndGrants(
                $this->runModel(),
                $this->configInput(),
                array_values((array) ($this->data['grants'] ?? [])),
            );
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title((string) __('imports.notifications.plan_invalid'))
                ->body((string) collect($exception->errors())->flatten()->first())->send();

            return;
        }

        $this->applyPlan($plan);
        Notification::make()->success()->title((string) __('imports.notifications.plan_saved'))->send();
    }

    /**
     * A table persisted a change, so the plan exists and the confirm step is
     * available.
     */
    #[On('import-plan-updated')]
    public function onPlanUpdated(): void
    {
        $this->planSaved = is_array($this->runModel()->plan);
    }

    /**
     * @return array<int, mixed>
     */
    private function layoutSchema(): array
    {
        $fields = [
            Select::make('layout')
                ->label((string) __('imports.fields.layout'))
                ->options($this->translatedOptions($this->planner()->layouts($this->driver()), 'imports.layouts'))
                ->required()
                ->native(false)
                ->live()
                ->afterStateUpdated(fn () => $this->onLayoutChanged()),
            Select::make('folder_axis')
                ->label((string) __('imports.fields.folder_axis'))
                ->options(fn (): array => $this->folderAxisOptions())
                ->required()
                ->native(false)
                ->live()
                ->afterStateUpdated(fn () => $this->onAxesChanged()),
        ];

        foreach (array_keys($this->driver()->axes()) as $axis) {
            $fields[] = Select::make($axis)
                ->label((string) __('imports.fields.axis_destination', ['axis' => __('imports.axes.'.$axis)]))
                ->options(fn (): array => $this->destinationOptions($axis))
                ->disabled(fn (): bool => count($this->destinationOptions($axis)) === 1)
                ->dehydrated()
                ->native(false)
                ->live()
                ->afterStateUpdated(fn () => $this->onAxesChanged($axis));
        }

        $fields[] = TextInput::make('single_vault_name')
            ->label((string) __('imports.fields.single_vault_name'))
            ->maxLength(ImportPlanner::MAX_NAME)
            ->visible(fn (): bool => ($this->data['layout'] ?? null) === ImportPlanner::SINGLE_VAULT)
            ->live(onBlur: true)
            ->afterStateUpdated(fn () => $this->onAxesChanged())
            ->columnSpanFull();

        return $fields;
    }

    /**
     * @return array<int, mixed>
     */
    private function vaultsSchema(): array
    {
        return [
            EmbeddedTable::make(ImportVaultsTable::class, ['run' => $this->run]),
        ];
    }

    /**
     * The step description, shown at the top of the step content instead of
     * the wizard header so the header stays compact.
     */
    private function stepIntro(string $step): Text
    {
        return Text::make((string) __('imports.wizard.step_descriptions.'.$step))
            ->color('gray')
            ->size(TextSize::Small);
    }

    /**
     * @return array<int, mixed>
     */
    private function accessSchema(): array
    {
        return [
            Select::make('default_custodian_user_id')
                ->label((string) __('imports.fields.default_custodian'))
                ->options(fn (): array => $this->userOptions())
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(fn () => $this->onAxesChanged())
                ->helperText((string) __('imports.access.default_custodian_helper')),
            Select::make('default_manager_user_id')
                ->label((string) __('imports.fields.default_manager'))
                ->options(fn (): array => $this->userOptions())
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(fn () => $this->onAxesChanged())
                ->helperText((string) __('imports.access.default_manager_helper')),
            Repeater::make('grants')
                ->hiddenLabel()
                ->addActionLabel((string) __('imports.access.add'))
                ->columns(2)
                ->columnSpanFull()
                ->live()
                ->afterStateUpdated(fn () => $this->onGrantsChanged())
                ->schema([
                    Select::make('principal_id')
                        ->label((string) __('imports.fields.principal'))
                        ->options(fn (): array => $this->principalOptions())
                        ->searchable()
                        ->required(),
                    Select::make('permission')
                        ->label((string) __('imports.fields.permission'))
                        ->options($this->translatedOptions(VaultMembership::PERMISSIONS, 'imports.permissions'))
                        ->required()
                        ->native(false),
                ])
                ->helperText((string) __('imports.access.helper')),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function tagsSchema(): array
    {
        return [
            EmbeddedTable::make(ImportTagsTable::class, ['run' => $this->run]),
        ];
    }

    private function onLayoutChanged(): void
    {
        $vaultAxis = $this->planner()->vaultAxisForLayout($this->driver(), (string) ($this->data['layout'] ?? ImportPlanner::SINGLE_VAULT));
        $this->data['folder_axis'] = $this->planner()->preferredFolderAxis($this->driver(), $vaultAxis);
        $this->onAxesChanged();
    }

    private function onAxesChanged(?string $changedAxis = null): void
    {
        $this->normalizeAxes($changedAxis);

        try {
            $plan = $this->planEditor()->applyConfigAndGrants(
                $this->runModel(),
                $this->configInput(),
                $this->completeGrants(),
            );
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title((string) __('imports.notifications.plan_invalid'))
                ->body((string) collect($exception->errors())->flatten()->first())->send();

            return;
        }

        $this->applyPlan($plan);
    }

    /**
     * Persist access-repeater changes as they happen, without re-filling the
     * form (which would drop a row the admin is still completing). Only rows
     * with both a principal and a role are saved, so a half-entered row never
     * blocks or errors the plan.
     */
    private function onGrantsChanged(): void
    {
        try {
            $this->planEditor()->applyConfigAndGrants(
                $this->runModel(),
                $this->configInput(),
                $this->completeGrants(),
            );
        } catch (ValidationException) {
            return;
        }

        $this->planSaved = true;
        $this->dispatch('import-plan-updated');
    }

    /**
     * The access-repeater rows that are complete enough to resolve.
     *
     * @return list<array<string, mixed>>
     */
    private function completeGrants(): array
    {
        return array_values(array_filter(
            (array) ($this->data['grants'] ?? []),
            fn (mixed $row): bool => is_array($row)
                && is_string($row['principal_id'] ?? null) && $row['principal_id'] !== ''
                && is_string($row['permission'] ?? null) && $row['permission'] !== '',
        ));
    }

    /**
     * Keep the layout, axes, and destinations a legal combination before the
     * planner sees them. The axis the admin just changed wins a conflict.
     */
    private function normalizeAxes(?string $changedAxis = null): void
    {
        $this->data = [
            ...$this->data,
            ...$this->planner()->normalize($this->driver(), $this->data, $changedAxis),
        ];
    }

    /** @return array<string, string> */
    private function folderAxisOptions(): array
    {
        $vaultAxis = $this->planner()->vaultAxisForLayout($this->driver(), (string) ($this->data['layout'] ?? ImportPlanner::SINGLE_VAULT));

        return $this->translatedOptions($this->planner()->folderAxisChoices($this->driver(), $vaultAxis), 'imports.axes');
    }

    /** @return array<string, string> */
    private function destinationOptions(string $axis): array
    {
        $destinations = [];
        foreach (array_keys($this->driver()->axes()) as $other) {
            if ($other !== $axis && is_string($this->data[$other] ?? null)) {
                $destinations[$other] = $this->data[$other];
            }
        }

        $allowed = $this->planner()->allowedDestinations(
            $this->driver(),
            $axis,
            (string) ($this->data['vault_axis'] ?? ImportPlanner::NONE),
            (string) ($this->data['folder_axis'] ?? ImportPlanner::NONE),
            $destinations,
        );

        return $this->translatedOptions($allowed, 'imports.destinations');
    }

    /** @return array<string, string> */
    private function userOptions(): array
    {
        return User::query()->where('is_active', true)->orderBy('name')->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} ({$user->email})"])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function principalOptions(): array
    {
        $options = [];
        foreach (app(PrincipalDirectory::class)->selectablePrincipals() as $principal) {
            $options[(string) $principal['id']] = (string) $principal['label'];
        }

        return $options;
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private function translatedOptions(array $values, string $prefix): array
    {
        $options = [];
        foreach ($values as $value) {
            $options[$value] = (string) __($prefix.'.'.$value);
        }

        return $options;
    }

    /**
     * The layout/axis configuration the wizard form owns.
     *
     * @return array<string, mixed>
     */
    private function configInput(): array
    {
        $config = [];
        foreach ($this->planEditor()->configKeys($this->driver()) as $key) {
            $config[$key] = $this->data[$key] ?? null;
        }

        return [
            ...$config,
            'single_vault_name' => $this->data['single_vault_name'] ?? null,
            'default_custodian_user_id' => $this->data['default_custodian_user_id'] ?? null,
            'default_manager_user_id' => $this->data['default_manager_user_id'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function applyPlan(array $plan): void
    {
        $this->data = $this->planToFormState($plan);
        $this->form->fill($this->data);
        $this->planSaved = true;
        $this->dispatch('import-plan-updated');
    }

    /**
     * Build the Livewire form state from a resolved (non-secret) plan.
     *
     * Vaults and tags are not form state: the two review tables read and edit
     * them directly against the run plan.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function planToFormState(array $plan): array
    {
        $singleVaultName = (string) ($this->data['single_vault_name'] ?? '');
        if (($plan['layout'] ?? null) === ImportPlanner::SINGLE_VAULT) {
            $singleVaultName = (string) ($plan['vaults']['vault']['name'] ?? $singleVaultName);
        }

        $defaults = is_array($plan['defaults'] ?? null) ? $plan['defaults'] : [];
        // Older stored plans predate the defaults key; fall back to the first
        // resolved vault so the pickers are never empty.
        $firstVault = collect(is_array($plan['vaults'] ?? null) ? $plan['vaults'] : [])
            ->first(fn (mixed $vault): bool => is_array($vault)) ?? [];

        $axes = $this->planner()->normalize($this->driver(), [
            ...(is_array($plan['axes'] ?? null) ? $plan['axes'] : []),
            'layout' => $plan['layout'] ?? ImportPlanner::SINGLE_VAULT,
        ]);

        return [
            ...$axes,
            'single_vault_name' => $singleVaultName,
            'default_custodian_user_id' => (string) ($defaults['custodian_user_id'] ?? $firstVault['custodian_user_id'] ?? $this->data['default_custodian_user_id'] ?? ''),
            'default_manager_user_id' => (string) ($defaults['manager_user_id'] ?? $firstVault['manager_user_id'] ?? $this->data['default_manager_user_id'] ?? ''),
            'grants' => array_values((array) ($plan['grants'] ?? [])),
        ];
    }

    /**
     * Non-secret counts for the export, rendered as Filament stat chips.
     *
     * @return array<int, mixed>
     */
    private function summarySchema(): array
    {
        $structure = is_array($this->preview['structure'] ?? null) ? $this->preview['structure'] : [];

        $stats = $this->driver()->previewStats();

        $cards = [];
        foreach ($stats as $stat) {
            $cards[] = Group::make([
                Text::make((string) __('imports.review.'.$stat['key']))
                    ->color('gray')
                    ->size(TextSize::Small),
                Text::make((string) ($structure[$stat['key']] ?? 0))
                    ->badge()
                    ->color($stat['color'])
                    ->icon($stat['icon']),
            ]);
        }

        return [
            Grid::make(['default' => 2, 'sm' => 3, 'lg' => min(5, max(1, count($cards)))])->schema($cards),
        ];
    }

    private function driver(): ImportDriver
    {
        return $this->planEditor()->driver($this->runModel());
    }

    private function planner(): ImportPlanner
    {
        return app(ImportPlanner::class);
    }

    private function planEditor(): ImportPlanEditor
    {
        return app(ImportPlanEditor::class);
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

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        Gate::authorize('manage-installation');

        return [
            'executeUrl' => route('imports.execute', ['run' => $this->run]),
        ];
    }
}
