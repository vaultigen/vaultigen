<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class EmergencyAccessStarted extends VaultigenMailable
{
    public function __construct(public int $auditSequence) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('mail.emergency_access.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.html.emergency-access',
            text: 'mail.text.emergency-access',
        );
    }
}
