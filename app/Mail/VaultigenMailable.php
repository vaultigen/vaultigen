<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;

/**
 * Base class for every Vaultigen notification email.
 *
 * The brand mark is embedded inline (CID) rather than linked, so it renders
 * even when a client blocks remote images by default.
 */
abstract class VaultigenMailable extends Mailable
{
    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromPath(public_path('vaultigen-icon.png'))
                ->as('vaultigen-icon.png')
                ->withMime('image/png'),
        ];
    }
}
