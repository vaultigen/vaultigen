<?php

use App\Auth\EditProfile;
use App\Auth\Login;
use App\Filament\Pages\EntryDetail;
use App\Models\User;
use App\Services\AdminRecovery;
use App\Services\InstallationSettings;
use App\Services\MfaPolicy;
use App\Services\ScopedGrants;
use App\Services\StrongAuthentication;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('inactivity and absolute lifetimes expire existing sessions', function (string $field, int $minutes) {
    $this->freezeTime();
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user)->withSession([
        'vaultigen.started_at' => now()->timestamp,
        'vaultigen.last_at' => now()->timestamp,
        'vaultigen.auth_version' => $user->auth_version,
        $field => now()->subMinutes($minutes)->timestamp,
    ])->get('/')->assertRedirect(route('filament.app.auth.login'));
    $this->assertGuest();
})->with([['vaultigen.last_at', 15], ['vaultigen.started_at', 720]]);

test('lower settings limits take effect on the next request', function () {
    $user = User::factory()->superAdmin()->withTwoFactor()->create();
    app(InstallationSettings::class)->save($user, [...app(InstallationSettings::class)->values(), 'inactivity_minutes' => 1]);
    $this->actingAs($user)->withSession(['vaultigen.last_at' => now()->subMinutes(2)->timestamp])
        ->get('/')->assertRedirect(route('filament.app.auth.login'));
});

test('deactivation invalidates a current session and panel eligibility', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user)->get('/')->assertOk();
    $user->forceFill(['is_active' => false])->save();
    $this->get('/')->assertRedirect(route('filament.app.auth.login'));
    expect($user->canAccessPanel(Filament::getPanel('app')))->toBeFalse();
});

test('local recovery invalidates a session even with a non database session driver', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin)->get('/')->assertOk();
    app(AdminRecovery::class)->recover($admin->email, 'temporary synthetic password');
    $this->get('/')->assertRedirect(route('filament.app.auth.login'));
    $this->assertGuest();
});

test('an authenticator can be removed and the account is forced back to set-up', function () {
    $user = User::factory()->withTwoFactor()->create();
    $user->saveAppAuthenticationSecret(null);
    expect($user->fresh()->app_authentication_secret)->toBeNull();
    expect(app(MfaPolicy::class)->userHasMethod($user->fresh()))->toBeFalse();
});

test('profile password changes require a fresh password plus totp and revoke other sessions', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);
    $code = AppAuthentication::make()->getCurrentCode($user, $user->app_authentication_secret);
    Livewire::test(EditProfile::class)->fillForm([
        'password' => 'replacement synthetic password', 'passwordConfirmation' => 'replacement synthetic password',
        'currentPassword' => 'password', 'confirmationCode' => $code,
    ], 'passwordForm')->call('savePassword')->assertHasNoErrors();
    expect(Hash::check('replacement synthetic password', $user->fresh()->password))->toBeTrue();
    expect(session('vaultigen.method'))->toBe('password_totp');
    expect(session('vaultigen.strong_at'))->toBeInt();
    $this->assertDatabaseHas('audit_events', ['action' => 'auth.reauthentication', 'outcome' => 'success']);
});

test('password only or replayed confirmation cannot authorize a sensitive change', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);
    $code = AppAuthentication::make()->getCurrentCode($user, $user->app_authentication_secret);
    $confirmation = app(StrongAuthentication::class);
    expect(fn () => $confirmation->confirm($user, 'password', 'invalid'))->toThrow(ValidationException::class);
    $confirmation->confirm($user, 'password', $code);
    expect(fn () => $confirmation->confirm($user, 'password', $code))->toThrow(ValidationException::class);
    $this->assertDatabaseHas('audit_events', ['action' => 'auth.reauthentication', 'outcome' => 'denied']);
});

test('confirmation attempts are rate limited', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);
    for ($attempt = 0; $attempt < 6; $attempt++) {
        expect(fn () => app(StrongAuthentication::class)->confirm($user, 'wrong', 'invalid'))->toThrow(ValidationException::class);
    }
});

test('password form submissions are rate limited', function () {
    $this->freezeTime();
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    $component = Livewire::test(EditProfile::class);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $component->call('savePassword');
    }

    $component->call('savePassword')->assertNotified('Too many requests. Please try again in 60 seconds.');
});

test('browser responses prevent caching framing and referrer leakage', function () {
    $this->get(route('filament.app.auth.login'))->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=()')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
});

test('hsts is omitted on plain http responses', function () {
    config(['vaultigen.hsts.max_age' => 31536000]);

    $this->get(str_replace('https://', 'http://', route('filament.app.auth.login')))
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('hsts reflects the configured policy over https', function () {
    config([
        'vaultigen.hsts.max_age' => 31536000,
        'vaultigen.hsts.include_subdomains' => false,
        'vaultigen.hsts.preload' => false,
    ]);

    $this->get(secure_url(route('filament.app.auth.login', absolute: false)))
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');

    config(['vaultigen.hsts.include_subdomains' => true, 'vaultigen.hsts.preload' => true]);
    $this->get(secure_url(route('filament.app.auth.login', absolute: false)))
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');

    config(['vaultigen.hsts.max_age' => 0]);
    $this->get(secure_url(route('filament.app.auth.login', absolute: false)))->assertHeaderMissing('Strict-Transport-Security');
});

test('login completes with encrypted challenge state and clears all submitted secrets', function () {
    $user = User::factory()->withTwoFactor()->create();
    $code = AppAuthentication::make()->getCurrentCode($user, $user->app_authentication_secret);
    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')->assertSet('data.password', null)
        ->fillForm(['app' => ['code' => $code]], 'multiFactorChallengeForm')
        ->call('authenticate')->assertHasNoErrors()->assertRedirect('/')
        ->assertSet('pendingCredentials', null)->assertSet('data.multiFactor.app.code', null);
    $this->assertAuthenticatedAs($user);
});

test('login challenge state expires and cannot move to another session or email', function (string $change) {
    $user = User::factory()->withTwoFactor()->create();
    $component = Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])->call('authenticate');
    match ($change) {
        'expiry' => $this->travel(6)->minutes(),
        'session' => session()->regenerate(),
        'email' => $component->set('data.email', 'different@example.test'),
    };
    $component->call('authenticate')->assertHasErrors(['data.email'])->assertSet('pendingCredentials', null)->assertSet('data.password', null);
    $this->assertGuest();
})->with(['expiry', 'session', 'email']);

test('a password only session cannot inherit assurance from enrollment in another session', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user)->withSession(['vaultigen.assurance' => 'setup'])
        ->get('/')->assertRedirect(route('filament.app.auth.login'));
    $this->assertGuest();
});

test('recovery code sign in grants recovery assurance and consumes the code once', function () {
    $user = User::factory()->withTwoFactor()->create();
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')->set('data.multiFactor.app.useRecoveryCode', true)
        ->fillForm(['app' => ['recoveryCode' => 'recovery-code-1']], 'multiFactorChallengeForm')
        ->call('authenticate')->assertHasNoErrors()->assertRedirect('/')
        ->assertSet('data.multiFactor.app.recoveryCode', null);
    expect(session('vaultigen.assurance'))->toBe('recovery');
    expect($user->fresh()->app_authentication_recovery_codes)->toBe([]);
    $this->get('/')->assertOk();
});

test('production profile passwords accept long passphrases and check compromised passwords', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);
    $this->app->detectEnvironment(fn (): string => 'production');
    $code = AppAuthentication::make()->getCurrentCode($user, $user->app_authentication_secret);
    Livewire::test(EditProfile::class)->set('passwordData', [
        'password' => 'long synthetic passphrase for tests', 'passwordConfirmation' => 'long synthetic passphrase for tests',
        'currentPassword' => 'password', 'confirmationCode' => $code,
    ])->assertSet('passwordData.password', 'long synthetic passphrase for tests')->call('savePassword')->assertOk()->assertHasNoErrors()->assertNotified('Saved')->assertSet('passwordData.currentPassword', null)->assertSet('passwordData.confirmationCode', null);
    expect(Hash::check('long synthetic passphrase for tests', $user->fresh()->password))->toBeTrue();
});

test('invalid totp challenges are audited without retaining the submitted code', function () {
    $user = User::factory()->withTwoFactor()->create();
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')->fillForm(['app' => ['code' => 'invalid']], 'multiFactorChallengeForm')
        ->call('authenticate')->assertHasErrors(['data.multiFactor.app.code'])
        ->assertSet('data.multiFactor.app.code', null);
    $this->assertDatabaseHas('audit_events', ['action' => 'auth.multi_factor', 'actor_id' => $user->id, 'outcome' => 'denied']);
    $this->assertGuest();
});

test('invalid strong confirmation shows a field error and leaves the password unchanged', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);
    Livewire::test(EditProfile::class)->fillForm([
        'password' => 'replacement synthetic password', 'passwordConfirmation' => 'replacement synthetic password',
        'currentPassword' => 'password', 'confirmationCode' => 'invalid',
    ], 'passwordForm')->call('savePassword')->assertHasErrors(['passwordData.confirmationCode'])->assertSet('passwordData.currentPassword', null);
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('changing the password is recorded in the activity log', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);
    $code = AppAuthentication::make()->getCurrentCode($user, $user->app_authentication_secret);

    Livewire::test(EditProfile::class)->set('passwordData', [
        'password' => 'replacement synthetic password', 'passwordConfirmation' => 'replacement synthetic password',
        'currentPassword' => 'password', 'confirmationCode' => $code,
    ])->call('savePassword')->assertHasNoErrors();

    $this->assertDatabaseHas('audit_events', ['action' => 'auth.password_changed', 'actor_id' => $user->id]);
});

test('changing the email is recorded in the activity log', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);
    $code = AppAuthentication::make()->getCurrentCode($user, $user->app_authentication_secret);

    Livewire::test(EditProfile::class)->set('data', [
        'name' => $user->name, 'email' => 'renamed@example.test',
        'profileCurrentPassword' => 'password', 'profileConfirmationCode' => $code,
    ])->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('audit_events', ['action' => 'auth.email_changed', 'actor_id' => $user->id]);
});

test('ordinary profile edits are not recorded as credential changes', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    Livewire::test(EditProfile::class)->fillForm(['name' => 'Renamed User', 'email' => $user->email])
        ->call('save')->assertHasNoFormErrors();

    $this->assertDatabaseMissing('audit_events', ['action' => 'auth.password_changed']);
    $this->assertDatabaseMissing('audit_events', ['action' => 'auth.email_changed']);
});

test('a session without an enrolled factor cannot reach vault data routes', function (string $routeName, array $parameters) {
    ['alice' => $alice, 'entryA' => $entry] = vaultigen_scope_fixture();
    $user = User::factory()->create();
    app(ScopedGrants::class)->grantOnEntry($alice, $entry->id, $user->principal_id, 'viewer');

    $this->actingAs($user)
        ->withSession(['vaultigen.assurance' => 'setup'])
        ->postJson(route($routeName, ['entry' => $entry->id, ...$parameters]))
        ->assertForbidden();

    expect(DB::table('audit_events')->where('action', 'entry.secret_delivered')->count())->toBe(0);
})->with([
    'field reveal' => ['entries.reveal', ['field' => 'password']],
    'custom field reveal' => ['entries.reveal_custom', ['index' => 0]],
    'authenticator code' => ['entries.totp', []],
]);

test('a browser request without an enrolled factor is sent to MFA set-up', function () {
    ['alice' => $alice, 'entryA' => $entry] = vaultigen_scope_fixture();
    $user = User::factory()->create();
    app(ScopedGrants::class)->grantOnEntry($alice, $entry->id, $user->principal_id, 'viewer');

    $this->actingAs($user)
        ->withSession(['vaultigen.assurance' => 'setup'])
        ->get(route('entries.icon', ['entry' => $entry->id]))
        ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
});

test('an enrolled session still reveals through the data routes', function () {
    ['alice' => $alice, 'entryA' => $entry] = vaultigen_scope_fixture();

    $this->actingAs($alice)
        ->withSession(['vaultigen.assurance' => 'strong'])
        ->postJson(route('entries.reveal', ['entry' => $entry->id, 'field' => 'password']))
        ->assertOk();
});

test('a replayed page snapshot cannot run after the factor is gone', function () {
    ['alice' => $alice, 'entryA' => $entry] = vaultigen_scope_fixture();
    $html = $this->actingAs($alice)
        ->withSession(['vaultigen.assurance' => 'strong'])
        ->get(EntryDetail::getUrl(['entry' => $entry->id]))
        ->assertOk()
        ->getContent();
    preg_match('/wire:snapshot="([^"]*EntryDetail[^"]*|[^"]*entry-detail[^"]*)"/', $html, $match);
    $snapshot = html_entity_decode($match[1]);
    $alice->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();

    $this->actingAs($alice)->withHeaders(['X-Livewire' => 'true'])
        ->postJson(Livewire::getUpdateUri(), ['_token' => csrf_token(), 'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]])
        ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
});
