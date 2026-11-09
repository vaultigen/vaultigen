<?php

namespace App\Filament\Resources\Vaults\Pages;

use App\Filament\Forms\Components\VaultIconSelect;
use App\Filament\Forms\Components\VaultImageUpload;
use App\Filament\Resources\Vaults\VaultResource;
use App\Models\User;
use App\Services\VaultAdministration;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

class ListVaults extends ListRecords
{
    protected static string $resource = VaultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label((string) __('vaults.actions.create_vault'))->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => VaultResource::canCreate())
                ->modalHeading((string) __('vaults.list_vaults.create_modal.heading'))
                ->modalDescription((string) __('vaults.list_vaults.create_modal.description'))
                ->modalSubmitActionLabel((string) __('vaults.actions.create_vault'))
                ->schema([
                    TextInput::make('name')->required()->maxLength(100),
                    Textarea::make('description')->rows(3)->maxLength(2000)
                        ->helperText((string) __('vaults.list_vaults.create_modal.metadata_helper')),
                    VaultIconSelect::make('icon')->placeholder((string) __('vaults.labels.no_icon')),
                    VaultImageUpload::make('image'),
                    Select::make('primary_custodian_user_id')->label((string) __('vaults.list_vaults.fields.primary_custodian'))
                        ->options(fn (): array => $this->userOptions())->searchable()->required()
                        ->helperText((string) __('vaults.list_vaults.fields.primary_custodian_helper')),
                    Select::make('initial_manager_user_id')->label((string) __('vaults.list_vaults.fields.initial_manager'))
                        ->options(fn (): array => $this->userOptions())->searchable()->required()
                        ->helperText((string) __('vaults.list_vaults.fields.initial_manager_helper')),
                ])
                ->action(function (array $data): void {
                    app(VaultAdministration::class)->create($this->actor(), $data);
                    Notification::make()->success()->title((string) __('vaults.notifications.vault_created'))
                        ->body((string) __('vaults.list_vaults.created_body'))->send();
                }),
        ];
    }

    /** @return array<string, string> */
    private function userOptions(): array
    {
        return User::query()->where('is_active', true)->orderBy('name')->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} ({$user->email})"])
            ->all();
    }

    private function actor(): User
    {
        Gate::authorize('manage-installation');

        /** @var User */
        return auth()->user();
    }
}
