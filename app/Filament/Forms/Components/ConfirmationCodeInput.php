<?php

namespace App\Filament\Forms\Components;

use App\Models\User;
use App\Services\MfaPolicy;
use App\Services\StrongAuthentication;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * A second-factor confirmation code field.
 *
 * When the signed-in user's active method is the emailed code, the field offers
 * a "Send code" action so the code can be requested from the same form that
 * asks for it. Authenticator-app users get no send action, because the code is
 * generated on their device.
 */
class ConfirmationCodeInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(function (): string {
                $user = $this->user();

                return (string) ($user !== null && app(MfaPolicy::class)->userMethod($user) === 'email'
                    ? __('security.fields.email_code')
                    : __('security.fields.authenticator_code'));
            })
            ->autocomplete('one-time-code')
            ->required()
            ->suffixAction(fn (): ?Action => $this->sendCodeAction());
    }

    private function sendCodeAction(): ?Action
    {
        $user = $this->user();

        if ($user === null || app(MfaPolicy::class)->userMethod($user) !== 'email') {
            return null;
        }

        return Action::make($this->getName().'SendCode')
            ->label((string) __('app.actions.send_code'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->action(function () use ($user): void {
                if (! app(StrongAuthentication::class)->sendCode($user)) {
                    Notification::make()->danger()->title((string) __('security.notifications.email_code_throttled'))->send();

                    return;
                }

                Notification::make()->success()->title((string) __('security.notifications.email_code_sent'))->send();
            });
    }

    private function user(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }
}
