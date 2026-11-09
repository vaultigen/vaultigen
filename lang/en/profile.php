<?php

return [

    'fields' => [
        'locale' => 'Language',
        'locale_helper' => 'The language used across Vaultigen.',
        'email' => 'Email address',
        'current_password' => 'Current password',
        'new_password' => 'New password',
        'confirm_new_password' => 'Confirm new password',
        'email_code' => 'Current email code',
        'authenticator_code' => 'Current authenticator code',
        'confirmation_code_helper' => 'Required with your current password when changing your password or email.',
    ],

    'sections' => [
        'profile_information' => 'Profile information',
        'profile_information_description' => 'Your name, and the email address you use to sign in.',
        'change_password' => 'Change password',
        'change_password_description' => 'Leave these fields empty to keep your current password. A new password must be confirmed with your current credentials and a verification code.',
        'two_factor' => 'Two-factor authentication',
        'two_factor_description' => 'Manage the method used to confirm your identity when signing in.',
        'active_sessions' => 'Active sessions',
        'active_sessions_description' => 'Devices and browsers currently signed in to your account.',
        'trusted_devices' => 'Trusted devices',
        'trusted_devices_description' => 'Browsers that may skip the second factor. Revoke any you do not recognize.',
    ],

    'notifications' => [
        'email_code_throttled' => 'A code was sent recently. Try again in a minute.',
        'email_code_sent' => 'A fresh code has been emailed to you.',
    ],

];
