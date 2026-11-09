<?php

namespace App\Filament\Forms\Components;

use App\Services\VaultAdministration;
use Filament\Forms\Components\Select;

class VaultIconSelect extends Select
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->options(function (): array {
                $options = [];

                foreach (VaultAdministration::ICONS as $icon => $label) {
                    $options[$icon] = view('filament.partials.vault-icon-option', [
                        'icon' => $icon,
                        'label' => $label,
                    ])->render();
                }

                return $options;
            })
            ->native(false)
            ->searchable()
            ->allowHtml();
    }
}
