<?php

namespace App\Auth;

use App\Models\User;
use App\Services\AuditWriter;
use App\Services\MfaPolicy;
use App\Services\TrustedDevices;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Forms\Components\Checkbox;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class Login extends \Filament\Auth\Pages\Login
{
    #[Locked]
    public ?string $pendingCredentials = null;

    public function authenticate(): ?LoginResponse
    {
        try {
            if (blank($this->data['password'] ?? null) && $this->pendingCredentials !== null) {
                $pending = decrypt($this->pendingCredentials);
                if ($pending['session'] !== session()->getId() || $pending['expires'] <= now()->timestamp
                    || $pending['email'] !== ($this->data['email'] ?? null)) {
                    $this->pendingCredentials = null;
                    $this->throwFailureValidationException();
                }
                $this->data['password'] = $pending['password'];
            }
            $response = parent::authenticate();
            if ($response === null && $this->userUndertakingMultiFactorAuthentication !== null) {
                $this->pendingCredentials = encrypt([
                    'email' => $this->data['email'], 'password' => $this->data['password'],
                    'session' => session()->getId(), 'expires' => now()->addMinutes(5)->timestamp,
                ]);
            } else {
                $this->pendingCredentials = null;
                $this->recordAssurance();
            }

            return $response;
        } catch (ValidationException $exception) {
            if ($this->userUndertakingMultiFactorAuthentication !== null) {
                app(AuditWriter::class)->record('auth.multi_factor', decrypt($this->userUndertakingMultiFactorAuthentication), 'denied');
            }

            throw $exception;
        } finally {
            $this->data['password'] = null;
            $this->data['multiFactor']['app']['code'] = null;
            $this->data['multiFactor']['app']['recoveryCode'] = null;
            $this->data['multiFactor']['email_code']['code'] = null;
            $this->data['multiFactor']['trustDevice'] = null;
        }
    }

    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()->hidden()->dehydrated(false);
    }

    protected function getMultiFactorChallenge(): MultiFactorChallenge
    {
        return TrustedDeviceMultiFactorChallenge::make();
    }

    public function defaultMultiFactorChallengeForm(Schema $schema): Schema
    {
        return $schema
            ->components(function (): array {
                $user = $this->getUserUndertakingMultiFactorAuthentication();

                return [
                    ...($user ? Arr::wrap($this->getMultiFactorProviderFormComponent()) : []),
                    ...($user ? $this->getMultiFactorChallenge()->getChallengeSchemaComponents($user) : []),
                    ...$this->getTrustDeviceFormComponents(),
                ];
            })
            ->statePath('data.multiFactor');
    }

    /**
     * @return array<Component>
     */
    protected function getTrustDeviceFormComponents(): array
    {
        if (! app(MfaPolicy::class)->trustedDevicesEnabled()) {
            return [];
        }

        return [
            Checkbox::make('trustDevice')
                ->label(__('auth.login.trusted_device'))
                ->helperText(__('auth.login.trusted_device_helper')),
        ];
    }

    /**
     * Derive the method and assurance for the session that just completed.
     */
    protected function recordAssurance(): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        $multiFactor = is_array($this->data['multiFactor'] ?? null) ? $this->data['multiFactor'] : [];
        $recovery = (bool) ($multiFactor['app']['useRecoveryCode'] ?? false);
        $trustedDevice = (bool) session()->pull('vaultigen.trusted_device_used', false);

        if ($trustedDevice && $user instanceof User) {
            $method = 'password_trusted_device';
            $assurance = 'trusted_device';
            app(TrustedDevices::class)->consume($user);
        } elseif ($this->usedAppCode($multiFactor)) {
            $method = $recovery ? 'password_recovery' : 'password_totp';
            $assurance = $recovery ? 'recovery' : 'strong';
        } elseif ($this->usedEmailCode($multiFactor)) {
            $method = 'password_email';
            $assurance = 'strong';
        } else {
            $method = 'password_setup';
            $assurance = 'setup';
        }

        session()->put('vaultigen.method', $method);
        session()->put('vaultigen.assurance', $assurance);
        session()->put('vaultigen.authenticated_at', now()->timestamp);

        if (($multiFactor['trustDevice'] ?? false) && $user instanceof User) {
            app(TrustedDevices::class)->remember($user);
        }
    }

    /**
     * @param  array<string, mixed>  $multiFactor
     */
    protected function usedAppCode(array $multiFactor): bool
    {
        return filled($multiFactor['app']['code'] ?? null)
            || filled($multiFactor['app']['recoveryCode'] ?? null)
            || (bool) ($multiFactor['app']['useRecoveryCode'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $multiFactor
     */
    protected function usedEmailCode(array $multiFactor): bool
    {
        return filled($multiFactor['email_code']['code'] ?? null);
    }

    public function getSubheading(): string
    {
        return (string) (app()->isLocal() ? __('auth.login.subheading_local') : __('auth.login.subheading'));
    }

    public function getHeading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getHeading();
        }

        return __('auth.login.heading');
    }
}
