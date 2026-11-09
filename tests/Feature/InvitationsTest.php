<?php

use App\Filament\Livewire\RecentInvitations;
use App\Filament\Pages\Users;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Invitations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

function inviteRecipient(): string
{
    Mail::fake();
    config(['mail.default' => 'smtp']);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    app(Invitations::class)->invite($admin, 'alice@example.test');

    return Mail::sent(InvitationMail::class)->sole()->token;
}

test('admin invites resends cancels and sees delivery and account details', function () {
    Mail::fake();
    config(['mail.default' => 'smtp']);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    Livewire::test(Users::class)->callAction('invite', ['email' => 'Alice@example.test'])->assertHasNoActionErrors()->assertDispatched('invitations-updated');
    $invitation = Invitation::query()->sole();
    $invitations = Livewire::test(RecentInvitations::class)->assertSee('alice@example.test')->assertSee('Sent');
    $invitations->callTableAction('resend', $invitation)->assertHasNoTableActionErrors();
    $this->assertDatabaseMissing('invitations', ['id' => $invitation->id, 'cancelled_at' => null]);
    $current = Invitation::query()->whereNull('cancelled_at')->sole();
    $invitations->callTableAction('cancel', $current)->assertHasNoTableActionErrors()->assertSee('Cancelled');
    Mail::assertSentCount(2);
    $this->get(Users::getUrl())->assertSee('Recent invitations')->assertSee($admin->email)->assertSee('alice@example.test');
});

test('invitation verifies intended email and creates an enrollment only session once', function () {
    $token = inviteRecipient();
    $this->get(route('invitation.show', $token))->assertSee('Accept your invitation')->assertHeader('Cache-Control', 'no-store, private');
    $data = ['token' => $token, 'email' => 'ALICE@example.test', 'name' => 'Alice', 'password' => 'a long synthetic password', 'password_confirmation' => 'a long synthetic password', 'policy' => '1'];
    $this->post(route('invitation.store'), $data)->assertRedirect(route('filament.app.auth.multi-factor-authentication.set-up-required'));
    $user = User::where('normalized_email', 'alice@example.test')->sole();
    expect($user->email_verified_at)->not->toBeNull();
    $this->assertDatabaseHas('principals', ['id' => $user->principal_id, 'kind' => 'user']);
    $this->get('/')->assertRedirect(route('filament.app.auth.multi-factor-authentication.set-up-required'));
    $this->post(route('filament.app.auth.logout'));
    $this->post(route('invitation.store'), $data)->assertSessionHasErrors('email');
});

test('unavailable invitations and different recipients cannot create an account', function (string $state) {
    $token = inviteRecipient();
    if ($state === 'expired') {
        $this->travel(25)->hours();
    }
    if ($state === 'cancelled') {
        DB::table('invitations')->update(['cancelled_at' => now()]);
    }
    $this->post(route('invitation.store'), ['token' => $token, 'name' => 'Alice', 'email' => $state === 'recipient' ? 'bob@example.test' : 'alice@example.test', 'password' => 'a long synthetic password', 'password_confirmation' => 'a long synthetic password', 'policy' => '1'])->assertSessionHasErrors('email');
    expect(User::count())->toBe(1);
    expect(session()->getOldInput('token'))->toBeNull();
})->with(['expired', 'cancelled', 'recipient']);

test('ordinary users cannot invoke account management and revoked admins cannot use open actions', function () {
    $ordinary = User::factory()->withTwoFactor()->create();
    $this->actingAs($ordinary)->get(Users::getUrl())->assertForbidden();
    Livewire::test(Users::class)->assertForbidden();
    Livewire::test(RecentInvitations::class)->assertForbidden();
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    $page = Livewire::test(Users::class)->mountAction('invite')->fillForm(['email' => 'alice@example.test']);
    DB::table('user_platform_roles')->where('user_id', $admin->id)->delete();
    $page->callMountedAction()->assertForbidden();
});

test('activation controls invalidate sessions and remember tokens', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $user = User::factory()->withTwoFactor()->create(['remember_token' => 'remember']);
    $this->actingAs($admin);
    $page = Livewire::test(Users::class)->callAction('deactivate', arguments: ['id' => $user->id])->assertHasNoActionErrors();
    expect($user->fresh()->is_active)->toBeFalse();
    expect($user->fresh()->remember_token)->toBeNull();
    $page->callAction('activate', arguments: ['id' => $user->id])->assertHasNoActionErrors();
    expect($user->fresh()->is_active)->toBeTrue();
});

test('invitation mail contains the acceptance link without persisting the raw token', function () {
    $token = inviteRecipient();
    $mail = new InvitationMail($token);
    expect($mail->render())->toContain(route('invitation.show', $token));
    expect($mail->envelope()->subject)->toBe('Your Vaultigen invitation');
    $this->assertDatabaseHas('invitations', ['token_digest' => hash('sha256', $token)]);
    $this->assertDatabaseMissing('invitations', ['token_digest' => $token]);
});

test('invitation delivery records sanitized failures and supports resend', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    app(Invitations::class)->invite($admin, 'alice@example.test');
    $this->assertDatabaseHas('invitations', ['failure_code' => 'mail_transport_unconfigured']);
    config(['mail.default' => 'smtp']);
    Mail::shouldReceive('to')->once()->with('alice@example.test')->andThrow(new RuntimeException('sensitive transport details'));
    app(Invitations::class)->resend($admin, DB::table('invitations')->value('id'));
    $this->assertDatabaseHas('invitations', ['failure_code' => 'mail_delivery_failed', 'delivered_at' => null]);
    $this->actingAs($admin);
    Livewire::test(RecentInvitations::class)
        ->assertSee('Mail not configured')
        ->assertSee('Delivery failed')
        ->assertDontSee('sensitive transport details');
});

test('invitation table filters by status and delivery state', function () {
    Mail::fake();
    config(['mail.default' => 'smtp']);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    app(Invitations::class)->invite($admin, 'pending@example.test');
    app(Invitations::class)->invite($admin, 'accepted@example.test');
    app(Invitations::class)->invite($admin, 'expired@example.test');
    app(Invitations::class)->invite($admin, 'cancelled@example.test');
    DB::table('invitations')->where('intended_normalized_email', 'accepted@example.test')->update(['consumed_at' => now()]);
    DB::table('invitations')->where('intended_normalized_email', 'expired@example.test')->update(['expires_at' => now()->subHour(), 'delivered_at' => null]);
    DB::table('invitations')->where('intended_normalized_email', 'cancelled@example.test')->update(['cancelled_at' => now()]);
    config(['mail.default' => 'log']);
    app(Invitations::class)->invite($admin, 'failed@example.test');

    $component = Livewire::test(RecentInvitations::class);

    $component->filterTable('status', 'accepted')->assertSee('accepted@example.test')->assertDontSee('pending@example.test');
    $component->filterTable('status', 'cancelled')->assertSee('cancelled@example.test')->assertDontSee('pending@example.test');
    $component->filterTable('status', 'expired')->assertSee('expired@example.test')->assertDontSee('pending@example.test');
    $component->filterTable('status', 'pending')->assertSee('pending@example.test')->assertDontSee('accepted@example.test');

    $deliveries = Livewire::test(RecentInvitations::class);
    $deliveries->filterTable('delivery', 'sent')->assertSee('pending@example.test')->assertDontSee('failed@example.test');
    $deliveries->filterTable('delivery', 'pending')->assertSee('expired@example.test')->assertDontSee('failed@example.test');
    $deliveries->filterTable('delivery', 'failed')->assertSee('failed@example.test');
});

test('invitation table refreshes when a new invitation is created', function () {
    Mail::fake();
    config(['mail.default' => 'smtp']);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    $component = Livewire::test(RecentInvitations::class)->assertSee('No invitations yet');
    app(Invitations::class)->invite($admin, 'alice@example.test');
    $component->dispatch('invitations-updated')->assertSee('alice@example.test');
});

test('resending without a mail transport warns instead of claiming delivery', function () {
    Mail::fake();
    config(['mail.default' => 'log']);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);
    app(Invitations::class)->invite($admin, 'alice@example.test');
    Livewire::test(RecentInvitations::class)
        ->callTableAction('resend', Invitation::query()->sole())
        ->assertHasNoTableActionErrors()
        ->assertNotified('Invitation replaced, but the email was not sent');
});
