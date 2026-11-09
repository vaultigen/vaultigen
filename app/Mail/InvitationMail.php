<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvitationMail extends VaultigenMailable
{
    public function __construct(#[\SensitiveParameter] public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('mail.invitation.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.html.invitation',
            text: 'mail.text.invitation',
        );
    }
}
