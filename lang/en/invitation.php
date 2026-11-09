<?php

return [

    'title' => 'Accept Vaultigen invitation',
    'heading' => 'Accept your invitation',
    'description' => 'The invitation link verifies the invited email address. After acceptance, enroll an authenticator before using Vaultigen.',
    'security_notice' => 'Vaultigen is organization-managed: the trusted server operator can access stored secrets, including in your personal work vault.',

    'fields' => [
        'name' => 'Name',
        'email' => 'Invited email',
        'password' => 'Password (at least 15 characters)',
        'password_confirmation' => 'Confirm password',
    ],

    'policy' => 'I understand the organization-managed security model.',
    'submit' => 'Verify email and enroll authentication',

];
