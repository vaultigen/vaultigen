<?php

namespace App\Filament\Resources\Vaults;

use App\Filament\Resources\Vaults\Pages\ListVaults;
use App\Filament\Resources\Vaults\Pages\ViewVault;
use App\Filament\Resources\Vaults\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Vaults\Schemas\VaultInfolist;
use App\Filament\Resources\Vaults\Tables\VaultsTable;
use App\Models\User;
use App\Models\Vault;
use App\Services\VaultAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The single administration screen for vaults.
 *
 * It merges what used to be separate "Vault shells", "Vault governance" and
 * "Vault members" pages: one table of the vaults you govern or manage, with
 * lifecycle actions shown only to the people allowed to run them, and a
 * Members relation manager on the record's view page.
 */
class VaultResource extends Resource
{
    protected static ?string $model = Vault::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return (string) __('vaults.model.label');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('vaults.model.plural');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('navigation.labels.vault_administration');
    }

    public static function infolist(Schema $schema): Schema
    {
        return VaultInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VaultsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->whereIn('id', app(VaultAccess::class)->administeredVaults($user)->select('vaults.id'))
            ->with(['custodian', 'owner', 'image:vault_id,checksum']);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVaults::route('/'),
            'view' => ViewVault::route('/{record}'),
        ];
    }
}
