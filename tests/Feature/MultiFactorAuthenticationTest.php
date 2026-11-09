<?php

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;

test('users must set up multi-factor authentication before accessing the panel', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('filament.app.auth.multi-factor-authentication.set-up-required'))
        ->assertOk();
});

test('users who finish set-up can access the panel', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test(SetUpRequiredMultiFactorAuthentication::class)
        ->callAction(TestAction::make('setUpAppAuthentication')->schemaComponent('app', 'content'));

    $arguments = decrypt($component->get('mountedActions')[0]['arguments']['encrypted']);
    $code = AppAuthentication::make()->getCurrentCode($user, $arguments['secret']);

    $component
        ->fillForm(['code' => $code, 'password' => 'password'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $user->refresh();

    expect($user->app_authentication_secret)->toBe($arguments['secret']);
    expect($user->app_authentication_recovery_codes)->not->toBeEmpty();

    $this->get('/')->assertOk();
});

test('users cannot set up app authentication with an invalid code', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(SetUpRequiredMultiFactorAuthentication::class)
        ->callAction(TestAction::make('setUpAppAuthentication')->schemaComponent('app', 'content'))
        ->fillForm(['code' => '000000', 'password' => 'password'])
        ->callMountedAction()
        ->assertHasFormErrors(['code']);

    $user->refresh();

    expect($user->app_authentication_secret)->toBeNull();
});

test('users can sign in with a recovery code', function () {
    $user = User::factory()->withTwoFactor()->create();
    $recoveryCodes = AppAuthentication::make()->generateRecoveryCodes();
    AppAuthentication::make()->saveRecoveryCodes($user, $recoveryCodes);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->fillForm(['app' => ['recoveryCode' => $recoveryCodes[0]]], 'multiFactorChallengeForm')
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);

    $user->refresh();

    expect($user->app_authentication_recovery_codes)->toHaveCount(count($recoveryCodes) - 1);
});

test('users cannot sign in with an invalid recovery code', function () {
    $user = User::factory()->withTwoFactor()->create();
    AppAuthentication::make()->saveRecoveryCodes($user, AppAuthentication::make()->generateRecoveryCodes());

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->fillForm(['app' => ['recoveryCode' => 'invalid-recovery-code']], 'multiFactorChallengeForm')
        ->call('authenticate')
        ->assertHasErrors(['data.multiFactor.app.recoveryCode']);

    $this->assertGuest();
});
