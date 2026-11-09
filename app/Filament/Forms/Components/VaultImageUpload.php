<?php

namespace App\Filament\Forms\Components;

use App\Services\VaultImages;
use Filament\Forms\Components\FileUpload;

/**
 * An optional vault avatar upload.
 *
 * The file is never stored as uploaded: it is handed to the action as a
 * temporary upload, and VaultAdministration re-encodes it to a small square.
 */
class VaultImageUpload extends FileUpload
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label((string) __('vaults.labels.image'))
            ->helperText((string) __('vaults.labels.image_helper'))
            ->image()
            ->acceptedFileTypes(VaultImages::ACCEPTED_MIMES)
            ->maxSize(VaultImages::MAX_UPLOAD_KILOBYTES)
            ->imageEditor()
            ->imageEditorAspectRatioOptions(['1:1'])
            ->storeFiles(false);
    }
}
