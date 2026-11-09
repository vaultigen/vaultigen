<?php

namespace App\Filament\Pages;

use App\Models\Team;
use App\Models\User;
use App\Services\TeamAdministration;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class Teams extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.teams';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    public static function canAccess(): bool
    {
        return Gate::allows('manage-installation');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) Team::query()->count();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Team::query())
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('members_count')->label((string) __('teams.columns.members'))
                    ->counts('members')
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('created_at')->label((string) __('teams.columns.created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([$this->createAction()])
            ->recordActions([
                Action::make('manage')->label((string) __('teams.actions.manage'))->icon(Heroicon::OutlinedCog6Tooth)
                    ->iconButton()->tooltip((string) __('teams.actions.manage'))
                    ->url(fn (Team $record): string => ManageTeam::getUrl(['team' => $record->getKey()])),
            ])
            ->defaultSort('name')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->emptyStateHeading((string) __('teams.empty.teams_heading'))
            ->emptyStateDescription((string) __('teams.empty.teams_description'));
    }

    public function createAction(): Action
    {
        return Action::make('create')->label((string) __('teams.actions.create'))->icon(Heroicon::OutlinedPlus)
            ->schema([TextInput::make('name')->required()->maxLength(255)])
            ->action(function (array $data): void {
                app(TeamAdministration::class)->save($this->actor(), null, $data['name']);
            });
    }

    private function actor(): User
    {
        Gate::authorize('manage-installation');

        /** @var User */
        return auth()->user();
    }
}
