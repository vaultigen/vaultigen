<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('guests are redirected to the Filament login page', function () {
    $this->get('/')->assertRedirect(route('filament.app.auth.login'));
});

test('the Filament login page is served at the domain root', function () {
    $this->get(route('filament.app.auth.login'))->assertOk();
});

test('users without multi-factor authentication are sent to set-up', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('filament.app.auth.multi-factor-authentication.set-up-required'));
});

test('multi-factor authentication gates every authenticated route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('filament.app.auth.profile'))
        ->assertRedirect(route('filament.app.auth.multi-factor-authentication.set-up-required'));
});

test('users with multi-factor authentication can access the panel', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create())
        ->get('/')
        ->assertOk();
});

test('no standalone routes exist outside the panel', function () {
    foreach (['home', 'dashboard', 'profile.edit', 'appearance.edit', 'security.edit'] as $removedRouteName) {
        expect(Route::has($removedRouteName))->toBeFalse();
    }

    foreach (['/dashboard', '/settings/profile', '/settings/appearance', '/settings/security', '/register', '/two-factor-challenge', '/forgot-password'] as $removedPath) {
        $this->get($removedPath)->assertNotFound();
    }
});
