<?php

use App\Auth\AppAuthentication;
use App\Auth\EditProfile;
use App\Auth\EmailAuthentication;
use App\Auth\Login;
use App\Filament\Pages\Settings;
use App\Filament\Pages\Users;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\VerifyEmailAuthenticationCode;
use App\Providers\AppServiceProvider;
use App\Services\AuditWriter;
use App\Services\InstallationSettings;
use App\Services\MfaPolicy;
use App\Services\StrongAuthentication;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;

test('the mfa policy reports the configured allowed methods and providers', function () {
    expect(app(MfaPolicy::class)->methods())->toBe(['app']);

    vaultigen_configure_mfa(['email']);
    expect(app(MfaPolicy::class)->methods())->toBe(['email'])
        ->and(app(MfaPolicy::class)->methodAllowed('email'))->toBeTrue()
        ->and(app(MfaPolicy::class)->methodAllowed('sms'))->toBeFalse()
        ->and(app(MfaPolicy::class)->providers()[0]->getId())->toBe('email_code');

    vaultigen_configure_mfa(['app', 'email']);
    expect(app(MfaPolicy::class)->providers())->toHaveCount(2);

    vaultigen_configure_mfa([]);
    expect(app(MfaPolicy::class)->methods())->toBe(['app']);
});

test('the panel registers only the allowed providers', function () {
    vaultigen_configure_mfa(['email']);
    expect(array_keys(Filament::getPanel('app')->getMultiFactorAuthenticationProviders()))->toBe(['email_code']);

    vaultigen_configure_mfa(['app']);
    expect(array_keys(Filament::getPanel('app')->getMultiFactorAuthenticationProviders()))->toBe(['app']);

    config(['vaultigen.mfa_disabled' => true]);
    expect(Filament::getPanel('app')->getMultiFactorAuthenticationProviders())->toBe([]);
});

test('the mfa policy falls back safely when installation settings cannot be read', function () {
    $this->app->instance(InstallationSettings::class, new class(app(AuditWriter::class)) extends InstallationSettings
    {
        public function values(): array
        {
            throw new RuntimeException('settings unavailable');
        }
    });

    $policy = app(MfaPolicy::class);

    expect(fn () => $policy->methods())->toThrow(RuntimeException::class)
        ->and($policy->providers())->toHaveCount(2)
        ->and($policy->rememberDeviceDays())->toBe(0)
        ->and($policy->trustedDevicesEnabled())->toBeFalse();
});

test('the mfa policy understands a users enrolled methods and assurances', function () {
    $policy = app(MfaPolicy::class);

    $none = User::factory()->create();
    expect($policy->userMethods($none))->toBe([])
        ->and($policy->userMethod($none))->toBeNull()
        ->and($policy->userHasMethod($none))->toBeFalse()
        ->and($policy->methodEnabled($none, 'sms'))->toBeFalse();

    $app = User::factory()->withTwoFactor()->create();
    expect($policy->userMethods($app))->toBe(['app'])
        ->and($policy->userMethod($app))->toBe('app')
        ->and($policy->userHasMethod($app))->toBeTrue();

    vaultigen_configure_mfa(['email']);
    $email = User::factory()->withEmailTwoFactor()->create();
    expect($policy->userMethods($email))->toBe(['email'])
        ->and($policy->methodEnabled($email, 'email'))->toBeTrue();
    expect($policy->userMethods($app))->toBe([])
        ->and($policy->userHasMethod($app))->toBeFalse();

    vaultigen_configure_mfa(['app']);
    expect($policy->sessionSatisfied($none, 'setup'))->toBeTrue()
        ->and($policy->sessionSatisfied($app, 'setup'))->toBeFalse()
        ->and($policy->sessionSatisfied($app, 'recovery'))->toBeTrue()
        ->and($policy->sessionSatisfied($app, 'trusted_device'))->toBeTrue()
        ->and($policy->acceptedAssurances())->toBe(['strong', 'recovery', 'trusted_device']);

    config(['vaultigen.mfa_disabled' => true]);
    expect($policy->enabled())->toBeFalse()
        ->and($policy->userHasMethod($none))->toBeTrue()
        ->and($policy->sessionSatisfied($app, null))->toBeTrue()
        ->and($policy->providers())->toBe([]);
});

test('the mfa policy gate exposes the trusted device setting', function () {
    expect(app(MfaPolicy::class)->trustedDevicesEnabled())->toBeFalse();

    vaultigen_configure_mfa(['app'], 30);
    expect(app(MfaPolicy::class)->rememberDeviceDays())->toBe(30)
        ->and(app(MfaPolicy::class)->trustedDevicesEnabled())->toBeTrue();

    config(['vaultigen.mfa_disabled' => true]);
    expect(app(MfaPolicy::class)->trustedDevicesEnabled())->toBeFalse();
});

test('the mfa bypass fails closed outside local and testing environments', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['vaultigen.mfa_disabled' => true]);

    expect(fn () => (new AppServiceProvider($this->app))->register())->toThrow(RuntimeException::class);
});

test('the mfa bypass is accepted in local and testing environments', function () {
    config(['vaultigen.mfa_disabled' => true]);

    (new AppServiceProvider($this->app))->register();

    expect(app(MfaPolicy::class)->enabled())->toBeFalse();

    $this->app->detectEnvironment(fn (): string => 'local');
    (new AppServiceProvider($this->app))->register();

    expect(app(MfaPolicy::class)->enabled())->toBeFalse();
});

test('installation settings persist and validate the mfa policy', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $settings = app(InstallationSettings::class);
    $base = $settings->values();

    expect(fn () => $settings->save($admin, [...$base, 'mfa_methods' => []]))->toThrow(ValidationException::class);
    expect(fn () => $settings->save($admin, [...$base, 'mfa_methods' => ['sms']]))->toThrow(ValidationException::class);
    expect(fn () => $settings->save($admin, [...$base, 'remember_device_days' => 400]))->toThrow(ValidationException::class);

    $settings->save($admin, [...$base, 'mfa_methods' => ['email'], 'remember_device_days' => 45]);

    $values = $settings->values();
    expect($values['mfa_methods'])->toBe(['email'])
        ->and($values['remember_device_days'])->toBe(45);
    $this->assertDatabaseHas('audit_events', ['action' => 'installation.settings_changed', 'actor_id' => $admin->id]);
});

test('the settings page renders the authentication section and blocks an empty method list', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $this->actingAs($admin);

    Livewire::test(Settings::class)->assertSee('Authentication')->assertSee('Remember this device');

    Livewire::test(Settings::class)
        ->fillForm(['mfa_methods' => []])
        ->call('save')
        ->assertHasFormErrors(['mfa_methods']);
});

test('users can enroll the email method from the forced set-up page', function () {
    vaultigen_configure_mfa(['email']);
    vaultigen_fake_email_code();
    Notification::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(SetUpRequiredMultiFactorAuthentication::class)
        ->callAction(TestAction::make('setUpEmailAuthentication')->schemaComponent('email_code', 'content'));

    Notification::assertSentTo($user, VerifyEmailAuthenticationCode::class);

    $component->fillForm(['code' => '654321'])->callMountedAction()->assertHasNoFormErrors();

    expect($user->fresh()->has_email_authentication)->toBeTrue()
        ->and(session('vaultigen.assurance'))->toBe('strong')
        ->and(session('vaultigen.method'))->toBe('password_email');
});

test('users can complete an email code challenge and gain strong assurance', function () {
    vaultigen_configure_mfa(['email']);
    vaultigen_fake_email_code();
    Notification::fake();

    $user = User::factory()->withEmailTwoFactor()->create();

    $component = Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertGuest();

    $component->fillForm(['email_code' => ['code' => '654321']], 'multiFactorChallengeForm')
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(session('vaultigen.assurance'))->toBe('strong')
        ->and(session('vaultigen.method'))->toBe('password_email');
});

test('users cannot complete an email code challenge with an invalid code', function () {
    vaultigen_configure_mfa(['email']);
    vaultigen_fake_email_code();
    Notification::fake();

    $user = User::factory()->withEmailTwoFactor()->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->fillForm(['email_code' => ['code' => '000000']], 'multiFactorChallengeForm')
        ->call('authenticate')
        ->assertHasErrors(['data.multiFactor.email_code.code']);

    $this->assertGuest();
});

test('email code verification is throttled per account', function () {
    vaultigen_configure_mfa(['email']);
    vaultigen_fake_email_code();
    Notification::fake();

    $user = User::factory()->withEmailTwoFactor()->create();
    $this->actingAs($user);

    $email = app(EmailAuthentication::class);
    expect($email->sendCode($user))->toBeTrue();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        expect($email->verifyCode('000000', $user))->toBeFalse();
    }

    expect($email->verifyCode('654321', $user))->toBeFalse();
});

test('email code verification without a resolvable user returns false', function () {
    expect(app(EmailAuthentication::class)->verifyCode('123456'))->toBeFalse();
});

test('only one mfa method can be enrolled at a time', function () {
    vaultigen_configure_mfa(['app', 'email']);

    $appUser = User::factory()->withTwoFactor()->create();
    $this->actingAs($appUser);
    $emailActions = app(EmailAuthentication::class)->getActions();
    expect($emailActions[0]->isHidden())->toBeTrue()
        ->and($emailActions[1]->isVisible())->toBeFalse();

    $emailUser = User::factory()->withEmailTwoFactor()->create();
    $this->actingAs($emailUser);
    $appActions = app(AppAuthentication::class)->getActions();
    expect($appActions[0]->isHidden())->toBeTrue()
        ->and($appActions[2]->isVisible())->toBeFalse();
    $emailActions = app(EmailAuthentication::class)->getActions();
    expect($emailActions[1]->isVisible())->toBeTrue();
});

test('an enabled method can be disabled and the other enrolled', function () {
    vaultigen_configure_mfa(['app', 'email']);

    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    $user->saveAppAuthenticationSecret(null);
    $user->refresh();

    expect($user->app_authentication_secret)->toBeNull();
    $emailActions = app(EmailAuthentication::class)->getActions();
    expect($emailActions[0]->isHidden())->toBeFalse();
});

test('the users page shows a method agnostic mfa column and filter', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $email = User::factory()->withEmailTwoFactor()->create();
    $none = User::factory()->create();
    $dual = User::factory()->withTwoFactor()->withEmailTwoFactor()->create();
    $this->actingAs($admin);

    Livewire::test(Users::class)->assertSee('App')->assertSee('Email')->assertSee('None')->assertSee('App + Email');

    Livewire::test(Users::class)
        ->filterTable('mfa_method', 'email')
        ->assertSee($email->email)
        ->assertDontSee($none->email);

    Livewire::test(Users::class)
        ->filterTable('mfa_method', 'app')
        ->assertSee($dual->email)
        ->assertDontSee($none->email);

    Livewire::test(Users::class)
        ->filterTable('mfa_method', 'none')
        ->assertSee($none->email)
        ->assertDontSee($email->email);
});

test('strong authentication is method agnostic', function () {
    vaultigen_configure_mfa(['email']);
    vaultigen_fake_email_code();
    Notification::fake();

    $user = User::factory()->withEmailTwoFactor()->create();
    $this->actingAs($user);

    $strong = app(StrongAuthentication::class);
    expect($strong->sendCode($user))->toBeTrue();
    $strong->confirm($user, 'password', '654321');
    expect(session('vaultigen.method'))->toBe('password_email');

    vaultigen_fake_email_code('111111');
    expect(fn () => $strong->confirm($user, 'password', '654321'))->toThrow(ValidationException::class);

    $appUser = User::factory()->withTwoFactor()->create();
    vaultigen_configure_mfa(['app']);
    expect($strong->sendCode($appUser))->toBeFalse();
    expect(fn () => $strong->confirm($appUser, 'password', '000000'))->toThrow(ValidationException::class);

    $none = User::factory()->create();
    expect(fn () => $strong->confirm($none, 'password', '000000'))->toThrow(ValidationException::class);
});

test('the profile reflects the email method and can send a code', function () {
    vaultigen_configure_mfa(['email']);
    vaultigen_fake_email_code();
    Notification::fake();

    $user = User::factory()->withEmailTwoFactor()->create();
    $this->actingAs($user);

    $component = Livewire::test(EditProfile::class)->assertSee('Current email code');
    $component->callAction(TestAction::make('sendPasswordEmailCode')->schemaComponent('confirmationCode', 'passwordForm'));
    Notification::assertSentTo($user, VerifyEmailAuthenticationCode::class);
});

test('the profile send code action reports throttling', function () {
    vaultigen_configure_mfa(['email']);
    vaultigen_fake_email_code();
    Notification::fake();

    $user = User::factory()->withEmailTwoFactor()->create();
    $this->actingAs($user);

    $action = fn () => TestAction::make('sendPasswordEmailCode')->schemaComponent('confirmationCode', 'passwordForm');

    Livewire::test(EditProfile::class)->callAction($action())->assertNotified('A fresh code has been emailed to you.');
    Livewire::test(EditProfile::class)->callAction($action())->assertNotified('A fresh code has been emailed to you.');
    Livewire::test(EditProfile::class)->callAction($action())->assertNotified('A code was sent recently. Try again in a minute.');
});

test('enrolling and disabling a factor is recorded in the activity log', function () {
    vaultigen_configure_mfa(['app', 'email']);

    $user = User::factory()->create();
    $this->actingAs($user);

    $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    expect(AuditEvent::query()->where('action', 'auth.factor_added')->count())->toBe(1);

    $user->saveAppAuthenticationSecret(null);
    $user->saveAppAuthenticationSecret(null);
    expect(AuditEvent::query()->where('action', 'auth.factor_removed')->count())->toBe(1);

    $user->toggleEmailAuthentication(true);
    $user->toggleEmailAuthentication(true);
    expect(AuditEvent::query()->where('action', 'auth.factor_added')->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'auth.factor_removed')->count())->toBe(1);

    $user->toggleEmailAuthentication(false);
    expect(AuditEvent::query()->where('action', 'auth.factor_removed')->count())->toBe(2)
        ->and(AuditEvent::query()->where('actor_id', $user->id)->whereIn('action', ['auth.factor_added', 'auth.factor_removed'])->count())->toBe(4);
});

test('the user model exposes method agnostic helpers', function () {
    vaultigen_configure_mfa(['app', 'email']);

    $user = User::factory()->create();
    expect($user->mfaMethodLabel())->toBe('None')
        ->and($user->hasStrongMethod())->toBeFalse();

    $user = User::factory()->withEmailTwoFactor()->create();
    expect($user->mfaMethods())->toBe(['email'])
        ->and($user->mfaMethodLabel())->toBe('Email')
        ->and($user->hasStrongMethod())->toBeTrue();

    $user = User::factory()->withTwoFactor()->create();
    expect($user->mfaMethodLabel())->toBe('App');

    $user->toggleEmailAuthentication(true);
    expect($user->mfaMethodLabel())->toBe('App + Email');
});

test('disabling remembered devices revokes them so re-enabling cannot revive them', function () {
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();
    $settings = app(InstallationSettings::class);
    vaultigen_configure_mfa(['app'], 30);
    vaultigen_issue_trusted_device($admin);

    $settings->save($admin, [...$settings->values(), 'remember_device_days' => 15]);
    expect(DB::table('trusted_devices')->count())->toBe(1);

    $settings->save($admin, [...$settings->values(), 'remember_device_days' => 0]);
    expect(DB::table('trusted_devices')->count())->toBe(0);
    $this->assertDatabaseHas('audit_events', ['action' => 'auth.trusted_device_revoked', 'actor_id' => $admin->id]);
});
