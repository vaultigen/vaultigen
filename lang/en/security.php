<?php

return [

    'confirmation_email' => 'Enter your current password and the code emailed to you.',
    'confirmation_app' => 'Enter your current password and a fresh authenticator code.',
    'recovery_single_super_admin' => 'Local recovery requires exactly one Super Admin.',

    'fields' => [
        'email_code' => 'Current email code',
        'authenticator_code' => 'Current authenticator code',
    ],

    'notifications' => [
        'email_code_sent' => 'A fresh code has been emailed to you.',
        'email_code_throttled' => 'A code was sent recently. Try again in a minute.',
    ],

];
