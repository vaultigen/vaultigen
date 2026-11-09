<?php

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;

test('the login page can be rendered', function () {
    Livewire::test(Login::class)->assertOk();
});

test('users with multi-factor authentication are challenged before authentication', function () {
    $user = User::factory()->withTwoFactor()->create();

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertGuest();
});

test('users are not authenticated with invalid credentials', function () {
    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ])
        ->call('authenticate')
        ->assertHasErrors(['data.email']);

    $this->assertGuest();
});

test('users can complete the multi-factor challenge on the login page', function () {
    $user = User::factory()->withTwoFactor()->create();
    $code = AppAuthentication::make()->getCurrentCode($user, $user->app_authentication_secret);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoErrors()
        ->fillForm(['app' => ['code' => $code]], 'multiFactorChallengeForm')
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

test('users are not authenticated with an invalid multi-factor code', function () {
    $user = User::factory()->withTwoFactor()->create();

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->fillForm(['app' => ['code' => '000000']], 'multiFactorChallengeForm')
        ->call('authenticate')
        ->assertHasErrors(['data.multiFactor.app.code']);

    $this->assertGuest();
});

test('the login endpoint is throttled against password guessing', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        Livewire::test(App\Auth\Login::class)
            ->fillForm(['email' => 'nobody@example.com', 'password' => 'wrong-password'])
            ->call('authenticate');
    }

    Livewire::test(App\Auth\Login::class)
        ->fillForm(['email' => 'nobody@example.com', 'password' => 'wrong-password'])
        ->call('authenticate')
        ->assertNotified('Too many login attempts');

    $this->assertGuest();
});

test('users can sign out', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->post(route('filament.app.auth.logout'));

    $this->assertGuest();
});
