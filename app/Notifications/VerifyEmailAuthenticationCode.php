<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;
use Symfony\Component\Mime\Email;

/**
 * The email-authentication sign-in code.
 *
 * Replaces Filament's default notification so the code arrives in the
 * Vaultigen dark theme instead of Laravel's stock light markdown mail. The
 * brand mark is embedded inline (CID) and renders even when remote images are
 * blocked.
 */
class VerifyEmailAuthenticationCode extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        #[SensitiveParameter]
        public string $code,
        public int $codeExpiryMinutes,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = [
            'code' => $this->code,
            'expiresInMinutes' => $this->codeExpiryMinutes,
        ];

        return (new MailMessage)
            ->subject((string) __('mail.verify_email_code.subject'))
            ->view('mail.html.verify-email-code', $data)
            ->text('mail.text.verify-email-code', $data)
            ->withSymfonyMessage(function (Email $message): void {
                $message->embedFromPath(public_path('vaultigen-icon.png'), 'vaultigen-icon.png');
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
