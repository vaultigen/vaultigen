<?php

return [

    'subheading' => 'Deactivate an account, revoke its sessions, and decide the fate of every vault it owns or governs. Deactivation immediately blocks sign-in and every authenticated request.',

    'actions' => [
        'offboard' => 'Deactivate and offboard',
    ],

    'sections' => [
        'account' => 'Account',
        'account_description' => 'The selected account is deactivated and all of its sessions and trusted devices are revoked.',
        'personal' => 'Personal work vaults',
        'personal_description' => 'Each personal work vault must be converted into a shared vault, archived, or marked for retention-bound purging.',
        'custodian' => 'Custodian responsibilities',
        'custodian_description' => 'Each shared vault this account governs needs a new Custodian. Custodianship grants no access to secrets.',
    ],

    'fields' => [
        'user' => 'Account',
        'vault' => 'Vault',
        'decision' => 'Decision',
        'new_custodian' => 'New Custodian',
        'new_manager' => 'New Manager',
    ],

    'decisions' => [
        'convert' => 'Convert to shared vault',
        'archive' => 'Archive',
        'purge' => 'Mark for purging',
    ],

    'report' => [
        'heading' => 'Credentials to rotate',
        'description' => 'These entries were revealed by this account. Rotate the corresponding external credentials after offboarding; only metadata is listed, never a secret value.',
        'empty' => 'This account revealed no credentials that are still stored.',
        'last_revealed' => 'last revealed :time',
        'reveal_count' => ':count reveal|:count reveals',
        'rotation_hint' => 'Revealing a value does not guarantee it was copied, and a rotated credential cannot be un-seen. Treat every listed entry as potentially exposed.',
    ],

    'notifications' => [
        'offboarded' => ':name has been offboarded',
        'not_offboarded' => 'Not offboarded',
    ],

    'errors' => [
        'cannot_offboard_self' => 'You cannot offboard your own account.',
        'last_super_admin' => 'You cannot offboard the last active Super Admin.',
        'user_required' => 'Choose an account to offboard.',
        'personal_decision_required' => 'Choose a decision for the personal work vault “:vault”.',
        'custodian_required' => 'Choose the new Custodian for “:vault”.',
        'custodian_invalid' => 'Choose an active account other than the one being offboarded.',
        'manager_required' => 'Choose the new Manager for “:vault”.',
        'manager_invalid' => 'Choose an active account other than the one being offboarded.',
        'transfer_required' => 'Choose a new Custodian for “:vault”.',
        'transfer_invalid' => 'Choose an active account other than the one being offboarded.',
        'invalid_decision' => 'Choose a valid decision for every personal work vault.',
    ],

];
