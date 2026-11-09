<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AdminRecovered extends VaultigenMailable
{
    public function __construct(public int $auditSequence) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('mail.admin_recovered.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.html.admin-recovered',
            text: 'mail.text.admin-recovered',
        );
    }
}
