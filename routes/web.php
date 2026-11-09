<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\Imports\ImportController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\Operations\OperationController;
use App\Http\Controllers\SiteIconController;
use App\Http\Controllers\VaultImageController;
use App\Http\Middleware\EnsureMfaEnrolled;
use Illuminate\Support\Facades\Route;

Route::middleware(['guest', 'throttle:10,1'])->group(function (): void {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/invitations', [InvitationController::class, 'store'])->name('invitation.store');
});

Route::middleware(['auth', EnsureMfaEnrolled::class])->group(function (): void {
    Route::post('/vaults/{vault}/entries', [EntryController::class, 'store'])->name('entries.store');
    Route::put('/vaults/{vault}/entries/{entry}', [EntryController::class, 'update'])->name('entries.update');
    Route::post('/entries/{entry}/reveal/{field}', [EntryController::class, 'reveal'])->name('entries.reveal');
    Route::post('/entries/{entry}/custom/{index}', [EntryController::class, 'revealCustom'])->name('entries.reveal_custom');
    Route::post('/entries/{entry}/totp', [EntryController::class, 'totp'])->name('entries.totp');
    Route::get('/entries/{entry}/icon', [SiteIconController::class, 'show'])->name('entries.icon');
    Route::get('/vaults/{vault}/image', [VaultImageController::class, 'show'])->name('vaults.image');

    Route::post('/vaults/{vault}/entries/{entry}/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])->name('attachments.download');
    Route::delete('/vaults/{vault}/entries/{entry}/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

    Route::middleware('can:manage-installation')->group(function (): void {
        Route::post('/imports', [ImportController::class, 'upload'])->name('imports.upload');
        Route::post('/imports/{run}/execute', [ImportController::class, 'execute'])->name('imports.execute');
        Route::post('/imports/{run}/cancel', [ImportController::class, 'cancel'])->name('imports.cancel');

        Route::post('/operations/rotations/{run}/resume', [OperationController::class, 'resumeRotation'])->name('operations.rotations.resume');
    });
});
