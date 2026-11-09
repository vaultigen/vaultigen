<?php

namespace App\Filament\Pages;

use App\Models\Team;
use App\Models\User;
use App\Services\TeamAdministration;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Rename one team and manage its members on a single screen.
 *
 * The teams list owns creation only. Everything that changes a team's name or
 * membership happens here so there is one obvious place for each team.
 */
class ManageTeam extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.manage-team';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    #[Locked]
    #[Url(as: 'team')]
    public string $team = '';

    public string $teamName = '';

    private ?Team $resolved = null;

    public function mount(): void
    {
        $this->authorizePage();
        $this->teamName = $this->teamModel()->name;
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
        return (string) __('teams.manage.title', ['name' => $this->teamModel()->name]);
    }

    public function getSubheading(): ?string
    {
        return (string) __('teams.manage.subheading');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label((string) __('teams.actions.back'))->icon(Heroicon::OutlinedArrowLeft)->color('gray')
                ->url(fn (): string => Teams::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                User::query()
                    ->whereIn('id', $this->teamModel()->members()->select('users.id'))
                    ->addSelect([
                        'membership_added_at' => DB::table('team_memberships')
                            ->select('created_at')
                            ->whereColumn('team_memberships.user_id', 'users.id')
                            ->where('team_memberships.team_id', $this->team)
                            ->limit(1),
                    ]),
            )
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable()->copyable(),
                TextColumn::make('platform_roles')->label((string) __('teams.columns.platform_roles'))
                    ->badge()
                    ->state(fn (User $record): array => $record->platformRoleNames())
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title())
                    ->colors(['danger' => 'super_admin', 'info' => 'auditor', 'warning' => 'vault_creator'])
                    ->placeholder((string) __('teams.fields.ordinary_user'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('membership_added_at')->label((string) __('teams.columns.added'))->dateTime()->sortable(),
                IconColumn::make('is_active')->label((string) __('teams.columns.active'))->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('email_verified_at')->label((string) __('teams.columns.verified'))->boolean()
                    ->state(fn (User $record): bool => $record->email_verified_at !== null)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('platform_role')->label((string) __('teams.filters.platform_role'))
                    ->options([
                        'super_admin' => (string) __('teams.filters.platform_role_options.super_admin'),
                        'auditor' => (string) __('teams.filters.platform_role_options.auditor'),
                        'vault_creator' => (string) __('teams.filters.platform_role_options.vault_creator'),
                    ])
                    ->query(function (Builder $query, array $state): Builder {
                        if (blank($state['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereExists(
                            DB::table('user_platform_roles')
                                ->join('platform_roles', 'platform_roles.id', '=', 'user_platform_roles.platform_role_id')
                                ->whereColumn('user_platform_roles.user_id', 'users.id')
                                ->where('platform_roles.name', $state['value']),
                        );
                    }),
                TernaryFilter::make('is_active')->label((string) __('teams.filters.active')),
            ])
            ->headerActions([$this->addMemberAction()])
            ->recordActions([$this->removeMemberAction()])
            ->bulkActions([$this->removeMembersAction()])
            ->defaultSort('membership_added_at', 'desc')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->emptyStateHeading((string) __('teams.empty.members_heading'))
            ->emptyStateDescription((string) __('teams.empty.members_description'))
            ->emptyStateIcon(Heroicon::OutlinedUserGroup)
            ->emptyStateActions([$this->addMemberAction()]);
    }

    public function addMemberAction(): Action
    {
        return Action::make('addMember')->label((string) __('teams.actions.add_member'))->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading((string) __('teams.actions.add_member_heading'))
            ->modalDescription((string) __('teams.actions.add_member_description'))
            ->modalSubmitActionLabel((string) __('teams.actions.add_member_submit'))
            ->schema([
                Select::make('user_id')->label((string) __('teams.fields.user'))
                    ->options(fn (): array => User::query()
                        ->whereNotIn('id', $this->teamModel()->members()->select('users.id'))
                        ->orderBy('name')
                        ->get(['id', 'name', 'email'])
                        ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} ({$user->email})"])
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                app(TeamAdministration::class)->membership($this->actor(), $this->team, (string) $data['user_id'], true);
                Notification::make()->success()->title((string) __('teams.notifications.member_added'))->send();
            });
    }

    public function removeMemberAction(): Action
    {
        return Action::make('removeMember')->label((string) __('teams.actions.remove'))->icon(Heroicon::OutlinedUserMinus)->color('danger')
            ->iconButton()->tooltip((string) __('teams.actions.remove_tooltip'))
            ->requiresConfirmation()
            ->modalHeading((string) __('teams.actions.remove_heading'))
            ->modalDescription((string) __('teams.actions.remove_description'))
            ->action(function (User $record): void {
                app(TeamAdministration::class)->membership($this->actor(), $this->team, $record->getKey(), false);
                Notification::make()->success()->title((string) __('teams.notifications.member_removed'))->send();
            });
    }

    public function removeMembersAction(): BulkAction
    {
        return BulkAction::make('removeMembers')->label((string) __('teams.actions.remove_selected'))->icon(Heroicon::OutlinedUserMinus)->color('danger')
            ->requiresConfirmation()
            ->modalDescription((string) __('teams.actions.remove_selected_description'))
            ->action(function (Collection $records): void {
                $actor = $this->actor();
                foreach ($records as $record) {
                    app(TeamAdministration::class)->membership($actor, $this->team, $record->getKey(), false);
                }
                Notification::make()->success()->title((string) __('teams.notifications.members_removed'))->send();
            });
    }

    public function renameTeam(): void
    {
        try {
            app(TeamAdministration::class)->save($this->actor(), $this->team, $this->teamName);
            $this->resolved = null;
            $this->teamName = $this->teamModel()->name;
            Notification::make()->success()->title((string) __('teams.notifications.renamed'))->send();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title((string) __('teams.notifications.not_renamed'))
                ->body((string) collect($exception->errors())->flatten()->first())->send();
        }
    }

    private function teamModel(): Team
    {
        return $this->resolved ??= Team::query()->findOrFail($this->team);
    }

    private function authorizePage(): void
    {
        abort_if($this->team === '', 404);
        $this->teamModel();
    }

    private function actor(): User
    {
        Gate::authorize('manage-installation');

        /** @var User */
        return auth()->user();
    }
}
