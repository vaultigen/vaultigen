<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VaultPurgeInitiated extends VaultigenMailable
{
    public function __construct(public int $auditSequence) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('mail.vault_purge_initiated.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.html.vault-purge-initiated',
            text: 'mail.text.vault-purge-initiated',
        );
    }
}
