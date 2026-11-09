<?php

namespace App\Filament\Livewire;

use App\Models\Folder;
use App\Models\User;
use App\Services\TrashLifecycle;
use App\Services\VaultAccess;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class TrashFolders extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public string $vault = '';

    public function mount(string $vault): void
    {
        $this->vault = $vault;
        $this->authorizeTrash();
    }

    public function hydrate(): void
    {
        $this->authorizeTrash();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(app(TrashLifecycle::class)->trashedFolders($this->user(), $this->vault))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('deleted_at')->label((string) __('trash.columns.deleted'))->dateTime()->sortable(),
                TextColumn::make('purge_after')->label((string) __('trash.columns.purge_after'))->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('restore')->label((string) __('trash.actions.restore'))->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->iconButton()->tooltip((string) __('trash.actions.restore'))
                    ->requiresConfirmation()
                    ->action(function (Folder $record): void {
                        try {
                            app(TrashLifecycle::class)->restoreFolder($this->user(), (string) $record->id);
                            Notification::make()->success()->title((string) __('trash.notifications.folder_restored'))->send();
                        } catch (ValidationException $exception) {
                            Notification::make()->danger()->title((string) __('trash.notifications.not_restored'))
                                ->body((string) collect($exception->errors())->flatten()->first())->send();
                        }
                    }),
            ])
            ->defaultSort('deleted_at', 'desc')
            ->striped()
            ->paginated([10, 25, 50])
            ->queryStringIdentifier('trash-folders')
            ->emptyStateHeading((string) __('trash.folders.empty_heading'))
            ->emptyStateDescription((string) __('trash.folders.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedFolderOpen);
    }

    public function render(): View
    {
        return view('filament.livewire.trash-folders');
    }

    private function authorizeTrash(): void
    {
        abort_unless(app(VaultAccess::class)->allows($this->user(), $this->vault, 'folder.delete'), 404);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
