<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class FactorsReset extends VaultigenMailable
{
    public function __construct(public int $auditSequence) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('mail.factors_reset.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.html.factors-reset',
            text: 'mail.text.factors-reset',
        );
    }
}
