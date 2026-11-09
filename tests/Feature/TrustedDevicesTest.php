<?php

use App\Auth\EditProfile;
use App\Auth\Login;
use App\Filament\Livewire\ActiveSessions;
use App\Filament\Livewire\TrustedDevices;
use App\Models\User;
use App\Notifications\VerifyEmailAuthenticationCode;
use App\Services\StrongAuthentication;
use App\Services\TrustedDevices as TrustedDevicesService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('the trusted devices service is a no-op until the feature is enabled', function () {
    $user = User::factory()->withTwoFactor()->create();

    app(TrustedDevicesService::class)->remember($user);

    expect(DB::table('trusted_devices')->count())->toBe(0)
        ->and(app(TrustedDevicesService::class)->currentSelector())->toBeNull();
});

test('a trusted device is stored hashed and audited', function () {
    vaultigen_configure_mfa(['app'], 30);
    $user = User::factory()->withTwoFactor()->create();

    app(TrustedDevicesService::class)->remember($user);

    $row = DB::table('trusted_devices')->where('user_id', $user->id)->sole();
    expect($row->token_hash)->not->toBeEmpty()
        ->and($row->expires_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_events', ['action' => 'auth.trusted_device_created', 'actor_id' => $user->id]);

    [$selector, $token] = vaultigen_issue_trusted_device($user);
    request()->cookies->set(TrustedDevicesService::COOKIE, $selector.'.'.$token);

    expect(app(TrustedDevicesService::class)->hasValidCookie($user))->toBeTrue()
        ->and(app(TrustedDevicesService::class)->currentSelector())->toBe($selector);
});

test('a trusted device cookie must be well formed fresh and owned by the user', function () {
    vaultigen_configure_mfa(['app'], 30);
    $user = User::factory()->withTwoFactor()->create();
    $other = User::factory()->withTwoFactor()->create();

    $service = app(TrustedDevicesService::class);

    request()->cookies->set(TrustedDevicesService::COOKIE, 'not-a-cookie');
    expect($service->hasValidCookie($user))->toBeFalse()
        ->and($service->currentSelector())->toBeNull();

    request()->cookies->set(TrustedDevicesService::COOKIE, '.token');
    expect($service->hasValidCookie($user))->toBeFalse();

    [$selector, $token] = vaultigen_issue_trusted_device($user);
    request()->cookies->set(TrustedDevicesService::COOKIE, $selector.'.wrong-token');
    expect($service->hasValidCookie($user))->toBeFalse();

    request()->cookies->set(TrustedDevicesService::COOKIE, $selector.'.'.$token);
    expect($service->hasValidCookie($other))->toBeFalse();

    DB::table('trusted_devices')->where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);
    expect($service->hasValidCookie($user))->toBeFalse();

    DB::table('trusted_devices')->where('user_id', $user->id)->update([
        'expires_at' => now()->addDay(), 'revoked_at' => now(),
    ]);
    expect($service->hasValidCookie($user))->toBeFalse();
});

test('using a trusted device rotates its secret and records the use', function () {
    vaultigen_configure_mfa(['app'], 30);
    $user = User::factory()->withTwoFactor()->create();
    [$selector, $token] = vaultigen_issue_trusted_device($user);
    request()->cookies->set(TrustedDevicesService::COOKIE, $selector.'.'.$token);

    app(TrustedDevicesService::class)->consume($user);

    $row = DB::table('trusted_devices')->where('user_id', $user->id)->sole();
    expect($row->token_hash)->not->toBe(hash('sha256', $token))
        ->and($row->last_used_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_events', ['action' => 'auth.trusted_device_used', 'actor_id' => $user->id]);

    app(TrustedDevicesService::class)->consume($user);
});

test('trusted devices can be revoked individually and in bulk', function () {
    vaultigen_configure_mfa(['app'], 30);
    $user = User::factory()->withTwoFactor()->create();
    [$currentSelector, $currentToken] = vaultigen_issue_trusted_device($user);
    [$otherSelector] = vaultigen_issue_trusted_device($user);
    request()->cookies->set(TrustedDevicesService::COOKIE, $currentSelector.'.'.$currentToken);

    $service = app(TrustedDevicesService::class);

    $service->revokeAllExceptCurrent($user);
    expect(DB::table('trusted_devices')->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::table('trusted_devices')->where('selector', $currentSelector)->exists())->toBeTrue();

    $service->revokeAllExceptCurrent($user);
    expect(DB::table('trusted_devices')->where('user_id', $user->id)->count())->toBe(1);

    request()->cookies->remove(TrustedDevicesService::COOKIE);
    $service->revokeAllExceptCurrent($user);
    expect(DB::table('trusted_devices')->where('user_id', $user->id)->count())->toBe(0);

    [$selector, $token] = vaultigen_issue_trusted_device($user);
    request()->cookies->set(TrustedDevicesService::COOKIE, $selector.'.'.$token);
    $service->revokeAll($user);
    expect(DB::table('trusted_devices')->where('user_id', $user->id)->count())->toBe(0);
    $this->assertDatabaseHas('audit_events', ['action' => 'auth.trusted_device_revoked', 'actor_id' => $user->id]);

    [$foreignSelector] = vaultigen_issue_trusted_device($user);
    $foreignId = DB::table('trusted_devices')->where('selector', $foreignSelector)->value('id');
    expect(fn () => $service->revoke(User::factory()->withTwoFactor()->create(), $foreignId))->toThrow(HttpException::class);

    $service->revoke($user, $foreignId);
    expect(DB::table('trusted_devices')->count())->toBe(0);
});

test('a valid trusted device skips the email challenge without sending a code', function () {
    vaultigen_configure_mfa(['email'], 30);
    vaultigen_fake_email_code();
    Notification::fake();

    $user = User::factory()->withEmailTwoFactor()->create();
    [$selector, $token] = vaultigen_issue_trusted_device($user);

    Livewire::withCookie(TrustedDevicesService::COOKIE, $selector.'.'.$token);
    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(session('vaultigen.assurance'))->toBe('trusted_device')
        ->and(session('vaultigen.method'))->toBe('password_trusted_device');

    Notification::assertNothingSent();
    $this->assertDatabaseHas('audit_events', ['action' => 'auth.trusted_device_used', 'actor_id' => $user->id]);
    expect(DB::table('trusted_devices')->where('user_id', $user->id)->value('token_hash'))->not->toBe(hash('sha256', $token));
});

test('an expired trusted device does not skip the email challenge', function () {
    vaultigen_configure_mfa(['email'], 30);
    vaultigen_fake_email_code();
    Notification::fake();

    $user = User::factory()->withEmailTwoFactor()->create();
    [$selector, $token] = vaultigen_issue_trusted_device($user, -1);

    Livewire::withCookie(TrustedDevicesService::COOKIE, $selector.'.'.$token);
    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertGuest();
    Notification::assertSentTo($user, VerifyEmailAuthenticationCode::class);
});

test('checking remember this device creates trust after a completed challenge', function () {
    vaultigen_configure_mfa(['email'], 30);
    vaultigen_fake_email_code();
    Notification::fake();

    $user = User::factory()->withEmailTwoFactor()->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->fillForm(['email_code' => ['code' => '654321'], 'trustDevice' => true], 'multiFactorChallengeForm')
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(DB::table('trusted_devices')->where('user_id', $user->id)->count())->toBe(1);
    $this->assertDatabaseHas('audit_events', ['action' => 'auth.trusted_device_created', 'actor_id' => $user->id]);
});

test('a trusted device session never satisfies sensitive re-authentication', function () {
    vaultigen_configure_mfa(['email'], 30);
    vaultigen_fake_email_code();
    Notification::fake();

    $user = User::factory()->withEmailTwoFactor()->create();
    [$selector, $token] = vaultigen_issue_trusted_device($user);

    Livewire::withCookie(TrustedDevicesService::COOKIE, $selector.'.'.$token);
    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertRedirect('/');

    expect(session('vaultigen.assurance'))->toBe('trusted_device');

    expect(fn () => app(StrongAuthentication::class)->confirm($user, 'password', '654321'))
        ->toThrow(ValidationException::class);
});

test('credential and state changes revoke trusted devices', function () {
    vaultigen_configure_mfa(['app'], 30);

    $user = User::factory()->withTwoFactor()->create();
    vaultigen_issue_trusted_device($user);
    $user->password = 'a different password';
    $user->save();
    expect(DB::table('trusted_devices')->where('user_id', $user->id)->count())->toBe(0);

    vaultigen_issue_trusted_device($user);
    $user->saveAppAuthenticationSecret(null);
    expect(DB::table('trusted_devices')->where('user_id', $user->id)->count())->toBe(0);

    $user->refresh()->forceFill(['is_active' => false])->save();
    vaultigen_issue_trusted_device($user);
    $user->forceFill(['is_active' => true])->save();
    vaultigen_issue_trusted_device($user);
    $user->forceFill(['is_active' => false])->save();
    expect(DB::table('trusted_devices')->where('user_id', $user->id)->count())->toBe(0);
});

test('revoking all other sessions keeps the current trusted device', function () {
    vaultigen_configure_mfa(['app'], 30);
    $user = User::factory()->withTwoFactor()->create();
    [$currentSelector, $currentToken] = vaultigen_issue_trusted_device($user);
    [$otherSelector] = vaultigen_issue_trusted_device($user);

    $this->actingAs($user);

    Livewire::withCookie(TrustedDevicesService::COOKIE, $currentSelector.'.'.$currentToken);
    Livewire::test(ActiveSessions::class)->callAction('revokeOthers');

    expect(DB::table('trusted_devices')->where('selector', $otherSelector)->exists())->toBeFalse()
        ->and(DB::table('trusted_devices')->where('selector', $currentSelector)->exists())->toBeTrue();
});

test('the profile lists and revokes trusted devices when enabled', function () {
    vaultigen_configure_mfa(['app'], 30);
    $user = User::factory()->withTwoFactor()->create();
    [$selector, $token] = vaultigen_issue_trusted_device($user);

    $this->actingAs($user);

    $this->get(EditProfile::getUrl())->assertOk()->assertSee('Trusted devices');
    Livewire::withCookie(TrustedDevicesService::COOKIE, $selector.'.'.$token);
    Livewire::test(TrustedDevices::class)->assertSee('This device');
    Livewire::withCookie(TrustedDevicesService::COOKIE, $selector.'.'.$token);
    Livewire::test(TrustedDevices::class)->callAction('revoke', arguments: ['id' => DB::table('trusted_devices')->where('selector', $selector)->value('id')]);
    expect(DB::table('trusted_devices')->where('user_id', $user->id)->count())->toBe(0);

    vaultigen_issue_trusted_device($user);
    Livewire::test(TrustedDevices::class)->callAction('revokeAll');
    expect(DB::table('trusted_devices')->where('user_id', $user->id)->count())->toBe(0);
});

test('the profile hides the trusted devices section when the feature is off', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    $this->get(EditProfile::getUrl())->assertOk()->assertDontSee('Trusted devices');
});

test('a trusted device stops skipping the challenge once the feature is disabled', function () {
    vaultigen_configure_mfa(['app'], 30);
    $user = User::factory()->withTwoFactor()->create();
    [$selector, $token] = vaultigen_issue_trusted_device($user);
    request()->cookies->set(TrustedDevicesService::COOKIE, $selector.'.'.$token);
    expect(app(TrustedDevicesService::class)->hasValidCookie($user))->toBeTrue();

    vaultigen_configure_mfa(['app'], 0);

    expect(app(TrustedDevicesService::class)->hasValidCookie($user))->toBeFalse();
});

test('shortening the period also shortens already trusted devices', function () {
    vaultigen_configure_mfa(['app'], 30);
    $user = User::factory()->withTwoFactor()->create();
    [$selector, $token] = vaultigen_issue_trusted_device($user);
    DB::table('trusted_devices')->where('selector', $selector)->update(['created_at' => now()->subDays(10)]);
    request()->cookies->set(TrustedDevicesService::COOKIE, $selector.'.'.$token);
    expect(app(TrustedDevicesService::class)->hasValidCookie($user))->toBeTrue();

    vaultigen_configure_mfa(['app'], 7);

    expect(app(TrustedDevicesService::class)->hasValidCookie($user))->toBeFalse();
});
