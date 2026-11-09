<?php

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;

test('users can only access the app panel', function () {
    $user = User::factory()->create();

    $appPanel = Filament::getPanel('app');
    $otherPanel = app(Panel::class)->id('other');

    expect($user->canAccessPanel($appPanel))->toBeTrue();
    expect($user->canAccessPanel($otherPanel))->toBeFalse();
});

test('users have initials derived from their name', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);

    expect($user->initials())->toBe('AL');
});

test('the factory can produce multi-factor enabled and unverified users', function () {
    $mfaUser = User::factory()->withTwoFactor()->create();
    $unverifiedUser = User::factory()->unverified()->create();

    expect($mfaUser->app_authentication_secret)->not->toBeNull();
    expect($unverifiedUser->email_verified_at)->toBeNull();
});
