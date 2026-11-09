<?php

namespace App\Filament\Livewire;

use App\Models\User;
use App\Services\AuditWriter;
use App\Services\MfaPolicy;
use App\Services\TrustedDevices;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class ActiveSessions extends Component implements HasActions, HasSchemas
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
        return Action::make('revoke')->label((string) __('devices.sessions.revoke'))->requiresConfirmation()->action(function (array $arguments): void {
            $user = $this->account();
            app(AuditWriter::class)->transaction(function () use ($user, $arguments): void {
                abort_if($arguments['id'] === session()->getId(), 422, (string) __('devices.sessions.use_sign_out'));
                abort_unless(DB::table('sessions')->where('user_id', $user->id)->where('id', $arguments['id'])->delete() > 0, 404);
                DB::table('users')->where('id', $user->id)->update(['remember_token' => null]);
                app(AuditWriter::class)->record('auth.session_revoked', $user->id);
            });
        });
    }

    public function revokeOthersAction(): Action
    {
        return Action::make('revokeOthers')->label((string) __('devices.sessions.revoke_others'))->requiresConfirmation()->action(function (): void {
            $user = $this->account();
            app(AuditWriter::class)->transaction(function () use ($user): void {
                DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();
                DB::table('users')->where('id', $user->id)->update(['remember_token' => null]);
                DB::table('users')->where('id', $user->id)->increment('auth_version');
                app(TrustedDevices::class)->revokeAllExceptCurrent($user);
                session()->put('vaultigen.auth_version', $user->fresh()->auth_version);
                app(AuditWriter::class)->record('auth.other_sessions_revoked', $user->id);
            });
        });
    }

    public function render(): View
    {
        $user = $this->account();

        return view('filament.livewire.active-sessions', [
            'sessions' => DB::table('sessions')->where('user_id', $user->id)->select('id', 'ip_address', 'user_agent', 'last_activity')->orderByDesc('last_activity')->get(),
        ]);
    }

    private function account(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->is_active && ! $user->must_reset_password && app(MfaPolicy::class)->userHasMethod($user), 403);

        return $user;
    }
}
