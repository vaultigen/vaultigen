<?php

return [

    'layout' => [
        'footer' => 'This is an automated message from :app. Do not reply to this email.',
    ],

    'verify_email_code' => [
        'subject' => 'Vaultigen sign-in code',
        'kicker' => 'Sign-in code',
        'title' => 'Confirm your sign-in',
        'body' => 'Enter this code to finish signing in to Vaultigen.',
        'code_label' => 'Your sign-in code',
        'expires' => 'This code expires in a minute.|This code expires in :minutes minutes.',
        'note' => 'Never share this code. Vaultigen will never ask you for it.',
    ],

    'admin_recovered' => [
        'subject' => 'Vaultigen: local administrator recovery',
        'kicker' => 'Administrator recovery',
        'title' => 'Local administrator recovered',
        'body' => 'A host operator recovered the sole Vaultigen administrator. Existing sessions and authentication factors were revoked.',
        'footer' => 'If unexpected, contact your host operator. Audit event: :sequence',
    ],

    'vault_purge_initiated' => [
        'subject' => 'Vaultigen: vault marked for deletion',
        'kicker' => 'Vault deletion',
        'title' => 'A vault was marked for deletion',
        'body' => 'Nobody can access the vault any more, and it will be deleted permanently when the waiting period ends. Until then, a Super Admin can restore it in Vault administration.',
        'footer' => 'If unexpected, have a Super Admin review this now. Audit event: :sequence',
    ],

    'factors_reset' => [
        'subject' => 'Vaultigen: authentication factors reset',
        'kicker' => 'Security alert',
        'title' => 'Authentication factors reset',
        'body' => 'A Vaultigen administrator reset your authentication factors. All sessions and recovery codes were revoked. Sign in with your password and enroll your authenticator again.',
        'footer' => 'If unexpected, contact your administrator immediately. Audit event: :sequence',
    ],

    'emergency_access' => [
        'subject' => 'Vaultigen: emergency administrator access started',
        'kicker' => 'Emergency access',
        'title' => 'Emergency access started',
        'body' => 'A Vaultigen administrator started time-limited emergency access to a vault. The session is read-only, expires within 30 minutes, and is prominently audited.',
        'footer' => 'If unexpected, investigate immediately. Audit event: :sequence',
    ],

    'invitation' => [
        'subject' => 'Your Vaultigen invitation',
        'kicker' => 'Invitation',
        'title' => 'You have been invited',
        'intro' => 'You have been invited to Vaultigen. This invitation expires in 24 hours and can be used once.',
        'accept' => 'Accept invitation and verify your email',
        'ignore' => 'If you were not expecting this invitation, ignore this email.',
    ],

    'test' => [
        'subject' => 'Vaultigen test email',
        'kicker' => 'Test message',
        'title' => 'Test email delivery',
        'body' => 'This is a test email from Vaultigen, sent from the installation status page to confirm mail delivery.',
        'footer' => 'If you received it, the configured mail transport is working. This message contains no secrets.',
    ],

];
