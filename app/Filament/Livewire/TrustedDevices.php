<?php

namespace App\Filament\Livewire;

use App\Models\User;
use App\Services\MfaPolicy;
use App\Services\TrustedDevices as TrustedDevicesService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class TrustedDevices extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public function mount(): void
    {
        $this->account();
    }

    public function hydrate(): void
    {
        $this->account();
    }

    public function revokeAction(): Action
    {
        return Action::make('revoke')->label((string) __('devices.trusted.revoke'))->requiresConfirmation()->action(function (array $arguments): void {
            $user = $this->account();
            app(TrustedDevicesService::class)->revoke($user, $arguments['id']);
        });
    }

    public function revokeAllAction(): Action
    {
        return Action::make('revokeAll')->label((string) __('devices.trusted.revoke_all'))->color('danger')->requiresConfirmation()->action(function (): void {
            $user = $this->account();
            app(TrustedDevicesService::class)->revokeAll($user);
        });
    }

    public function render(): View
    {
        $user = $this->account();

        return view('filament.livewire.trusted-devices', [
            'devices' => DB::table('trusted_devices')->where('user_id', $user->id)
                ->orderByDesc('last_used_at')->orderByDesc('created_at')->get(),
            'currentSelector' => app(TrustedDevicesService::class)->currentSelector(),
        ]);
    }

    public function deviceLabel(?string $userAgent): string
    {
        $userAgent = (string) $userAgent;

        $browser = match (true) {
            str_contains($userAgent, 'Edg') => (string) __('devices.browsers.edge'),
            str_contains($userAgent, 'Chrome') => (string) __('devices.browsers.chrome'),
            str_contains($userAgent, 'Firefox') => (string) __('devices.browsers.firefox'),
            str_contains($userAgent, 'Safari') => (string) __('devices.browsers.safari'),
            default => (string) __('devices.browsers.unknown'),
        };

        $platform = match (true) {
            str_contains($userAgent, 'Windows') => (string) __('devices.platforms.windows'),
            str_contains($userAgent, 'Android') => (string) __('devices.platforms.android'),
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => (string) __('devices.platforms.ios'),
            str_contains($userAgent, 'Mac OS') => (string) __('devices.platforms.macos'),
            str_contains($userAgent, 'Linux') => (string) __('devices.platforms.linux'),
            default => (string) __('devices.platforms.unknown'),
        };

        return (string) __('devices.device_label', ['browser' => $browser, 'platform' => $platform]);
    }

    private function account(): User
    {
        $policy = app(MfaPolicy::class);

        $user = auth()->user();
        abort_unless($user instanceof User && $user->is_active && ! $user->must_reset_password
            && $policy->userHasMethod($user) && $policy->trustedDevicesEnabled(), 403);

        return $user;
    }
}
