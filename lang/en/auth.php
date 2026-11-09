<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    'login' => [
        'heading' => 'Unlock your vault',
        'tagline' => 'Self-hosted password management',
        'subheading_local' => 'Local development. Sign in with admin@admin.com / password after running migrate:fresh --seed. Seeded test accounts: manager@manager.com, editor@editor.com, viewer@viewer.com (password).',
        'subheading' => 'Invite-only access. This installation trusts its host operator, who can access stored secrets. Enroll your allowed second factor after first sign-in; if you choose the authenticator app, save your offline recovery codes.',
        'trusted_device' => 'Remember this device',
        'trusted_device_helper' => 'Skip the code step on this browser for a limited time. Password changes and account resets forget it.',
    ],

];
