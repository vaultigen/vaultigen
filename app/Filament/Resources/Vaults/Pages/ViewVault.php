<?php

namespace App\Filament\Resources\Vaults\Pages;

use App\Filament\Resources\Vaults\Tables\VaultsTable;
use App\Filament\Resources\Vaults\VaultResource;
use Filament\Resources\Pages\ViewRecord;

class ViewVault extends ViewRecord
{
    protected static string $resource = VaultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            VaultsTable::editAction()->record($this->getRecord()),
        ];
    }
}
