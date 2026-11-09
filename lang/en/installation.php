<?php

return [

    'subheading' => 'Presence checks do not prove backup readiness. No key values or connection credentials are displayed.',

    'test_mail' => [
        'label' => 'Send a test email',
        'tooltip' => 'Send a test email',
        'modal_heading' => 'Send a test email',
        'modal_description' => 'Sends one message to confirm mail delivery. It contains no secrets and does not affect stored data.',
        'modal_submit' => 'Send test email',
        'email' => 'Recipient email address',
        'not_delivering_title' => 'Mail transport is not delivering',
        'not_delivering_body' => 'Configure a real mail transport before testing delivery. Nothing was sent.',
        'failed_title' => 'Test email was not sent',
        'failed_body' => 'Delivery failed. Check the mail transport configuration and the application log.',
        'sent_title' => 'Test email sent',
        'sent_body' => 'A test message was sent to :email.',
    ],

    'trust' => [
        'heading' => 'Trust and metadata',
        'server_trusted' => [
            'heading' => 'Server-trusted architecture',
            'body' => 'Vaultigen is a server-trusted password manager. The host operator and the running application can decrypt stored secrets. It provides no zero knowledge and no protection from a compromised host.',
        ],
        'metadata' => [
            'heading' => 'Metadata is visible',
            'body' => 'Database-only disclosure can expose names, emails, teams, grants, vault and folder structure, entry titles and types, sanitized URL hosts, ownership, timestamps, and audit metadata. Credential usernames, complete URLs, filenames, private notes, and custom values belong in encrypted payloads.',
        ],
        'personal_vaults' => [
            'heading' => 'Personal vaults are organization-managed',
            'body' => 'A personal work vault is not private from the trusted server operator. A Super Admin role alone grants no access to vault secrets.',
        ],
        'audit_checkpoints' => [
            'heading' => 'Audit checkpoints',
            'body' => 'Local audit checkpoints detect changes without the external integrity key. A host operator controlling that key can rewrite the chain; complete rollback detection requires a separately preserved checkpoint.',
        ],
    ],

    'summary' => [
        'needs_attention' => 'Installation needs attention',
        'review_recommended' => 'Review recommended',
        'all_passing' => 'All checks passing',
        'all_passing_description' => 'Every readiness check passed. Presence checks do not prove that backups are restorable.',
        'failed_required' => '{1} :count required configuration check failed|[2,*] :count required configuration checks failed',
        'review_suffix' => '{1} and :count operational check still needs review.|[2,*] and :count operational checks still need review.',
        'review_only' => '{1} All required configuration is present, but :count operational check still needs review.|[2,*] All required configuration is present, but :count operational checks still need review.',
    ],

    'checks' => [
        'database' => [
            'label' => 'Database',
            'status' => ':driver — connected',
        ],
        'sodium' => [
            'label' => 'Sodium',
            'ok' => 'Available',
            'error' => 'Missing — enable the PHP sodium extension',
        ],
        'installation_identity' => [
            'label' => 'Installation identity',
            'ok' => 'Present',
            'error' => 'Missing or changed — restore the protected installation configuration',
        ],
        'application_key' => [
            'label' => 'Application key',
            'ok' => 'Present',
            'error' => 'Missing — provision APP_KEY',
        ],
        'security_keys' => [
            'label' => 'Independent security keys',
            'ok' => 'Present',
            'error' => 'Missing or invalid — run vaultigen:initialize',
        ],
        'storage' => [
            'label' => 'Storage headroom',
            'ok' => ':free MB free',
            'low' => 'Only :free MB free — free space or move backups off-host',
            'unknown' => 'Storage path unavailable — check VAULTIGEN_STORAGE_PATH',
        ],
        'mail' => [
            'label' => 'Mail',
            'ok' => 'Configured — verify delivery with your mail operator',
            'warning' => 'Not delivering — configure a real mail transport',
        ],
        'scheduler' => [
            'label' => 'Scheduler',
            'ok' => 'Recent heartbeat',
            'warning' => 'Not verified — run schedule:run every minute',
        ],
        'queue' => [
            'label' => 'Queue',
            'ok' => 'Recent worker heartbeat',
            'warning' => 'Not verified — configure a durable queue and run queue:work',
        ],
        'security_notifications' => [
            'label' => 'Security notifications',
            'status' => ':count pending; scheduler retries mail delivery',
        ],
        'license' => [
            'label' => 'License',
            'free' => 'Free edition: up to :limit users. Install a license to add more.',
            'licensed' => 'Licensed to :licensee (:license_id).',
            'updates_until' => 'Updates until :date.',
            'lifetime' => 'Lifetime updates.',
            'users_limited' => 'Up to :count users.',
            'users_unlimited' => 'Unlimited users.',
            'updates_expired' => 'The license for :licensee covers versions released until :date; this version was released on :release. Up to :limit users until the license is renewed.',
            'invalid' => 'The installed license is not valid: :message The installation runs as the free edition with up to :limit users.',
        ],
    ],

];
