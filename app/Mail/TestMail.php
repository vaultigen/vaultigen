<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TestMail extends VaultigenMailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('mail.test.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.html.test',
            text: 'mail.text.test',
        );
    }
}
