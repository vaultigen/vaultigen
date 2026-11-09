<?php

namespace App\Services;

use App\Mail\InvitationMail;
use App\Models\User;
use App\Services\Licensing\SeatLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class Invitations
{
    public function __construct(private AuditWriter $audit, private SeatLimit $seats) {}

    public function invite(User $actor, string $email): void
    {
        Gate::forUser($actor)->authorize('manage-installation');
        $email = Str::lower(trim($email));
        Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:254', 'unique:users,normalized_email']])->validate();
        $token = Str::random(64);
        $id = $this->seats->auditingRejections($actor->id, fn (): string => $this->audit->transaction(function () use ($actor, $email, $token): string {
            $this->seats->ensureCanInvite($email);
            DB::table('invitations')->where('intended_normalized_email', $email)->whereNull('consumed_at')->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
            $id = (string) Str::uuid();
            DB::table('invitations')->insert([
                'id' => $id, 'token_digest' => hash('sha256', $token), 'intended_normalized_email' => $email,
                'invited_by_user_id' => $actor->id, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('invitation.created', $actor->id);

            return $id;
        }));
        if (in_array(config('mail.default'), ['log', 'array'])) {
            DB::table('invitations')->where('id', $id)->update(['failure_code' => 'mail_transport_unconfigured']);

            return;
        }
        try {
            Mail::to($email)->send(new InvitationMail($token));
            DB::table('invitations')->where('id', $id)->update(['delivered_at' => now()]);
        } catch (Throwable) {
            DB::table('invitations')->where('id', $id)->update(['failure_code' => 'mail_delivery_failed']);
        }
    }

    public function cancel(User $actor, string $id): void
    {
        Gate::forUser($actor)->authorize('manage-installation');
        $this->audit->transaction(function () use ($actor, $id): void {
            abort_unless(DB::table('invitations')->where('id', $id)->whereNull('consumed_at')->whereNull('cancelled_at')->update(['cancelled_at' => now()]) > 0, 404);
            $this->audit->record('invitation.cancelled', $actor->id);
        });
    }

    public function resend(User $actor, string $id): void
    {
        Gate::forUser($actor)->authorize('manage-installation');
        $invitation = DB::table('invitations')->where('id', $id)->whereNull('consumed_at')->whereNull('cancelled_at')->first();
        abort_if($invitation === null, 404);
        $this->invite($actor, $invitation->intended_normalized_email);
    }

    public function accept(#[\SensitiveParameter] string $token, string $email, string $name, #[\SensitiveParameter] string $password): User
    {
        $email = Str::lower(trim($email));
        Validator::make(compact('email', 'name', 'password'), [
            'email' => ['required', 'email:rfc', 'max:254', 'unique:users,normalized_email'],
            'name' => ['required', 'string', 'max:255'], 'password' => ['required', Password::min(15), 'max:1024'],
        ])->validate();

        return $this->seats->auditingRejections(null, fn (): User => $this->audit->transaction(function () use ($token, $email, $name, $password): User {
            $invitation = DB::table('invitations')->where('token_digest', hash('sha256', $token))
                ->where('intended_normalized_email', $email)->whereNull('consumed_at')->whereNull('cancelled_at')->where('expires_at', '>', now())->first();
            if ($invitation === null) {
                throw ValidationException::withMessages(['email' => __('users.administration.invitation_unavailable')]);
            }
            $this->seats->ensureCanAccept();
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now(), 'policy_accepted_at' => now()])->save();
            DB::table('invitations')->where('id', $invitation->id)->update(['consumed_at' => now()]);
            $this->audit->record('invitation.accepted', $user->id);
            app(PersonalVaults::class)->provisionFor($user);

            return $user;
        }));
    }
}
