<?php

use App\Models\User;
use App\Notifications\VerifyEmailAuthenticationCode;

test('the email authentication code notification renders the themed mail', function () {
    $user = User::factory()->withEmailTwoFactor()->create();

    $notification = new VerifyEmailAuthenticationCode('123456', 10);
    $user->notify($notification);

    expect($notification->toArray($user))->toBe([])
        ->and($notification->via($user))->toBe(['mail']);
});
