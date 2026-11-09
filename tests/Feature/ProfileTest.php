<?php

use App\Models\User;
use Filament\Auth\Pages\EditProfile;
use Livewire\Livewire;

test('users can view their profile', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user);

    Livewire::test(EditProfile::class)->assertOk();
});

test('users can update their profile name', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => 'Renamed User',
            'email' => $user->email,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh())->name->toBe('Renamed User');
});
