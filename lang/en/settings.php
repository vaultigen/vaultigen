<?php

return [

    'subheading' => 'Only settings used by active workflows are available. Keys and host configuration are managed outside this form.',

    'actions' => [
        'save' => 'Save settings',
    ],

    'sections' => [
        'installation' => 'Installation',
        'installation_description' => 'How this installation is identified to users.',
        'vault_creation' => 'Vault creation',
        'vault_creation_description' => 'Who may create shared vaults, and how many. Creating a vault never grants access to its contents.',
        'retention' => 'Trash and history retention',
        'retention_description' => 'Deleted entries and folders stay recoverable until their purge deadline. History is pruned in the background; a value of 0 means no limit for that dimension.',
        'audit_retention' => 'Audit retention',
        'audit_retention_description' => 'How long security events are kept. Pruning runs in the background after preserving a keyed checkpoint; IP addresses and user agents are redacted separately and are never part of the hash chain.',
        'imports' => 'Import retention',
        'imports_description' => 'How long staged uploads and finished import runs are kept. Cleanup removes the encrypted source and trims failure lists; it never keeps credentials or plaintext.',
        'site_icons' => 'Site icons',
        'site_icons_description' => 'Show the website icon of each login in entry lists. The server fetches icons directly from the site; only public addresses are contacted and no third-party icon service is used.',
        'session_security' => 'Session security',
        'session_security_description' => 'Limits are enforced on every request, including for sessions that are already signed in.',
        'authentication' => 'Authentication',
        'authentication_description' => 'Multi-factor authentication is always required. Choose which methods users may enroll; email-only is permitted. If both are allowed, each user activates exactly one.',
        'security_notifications' => 'Security notifications',
        'security_notifications_description' => 'These recipients receive alerts about recovery, authentication resets, and other sensitive account events.',
    ],

    'fields' => [
        'display_name' => 'Display name',
        'display_name_placeholder' => 'Vaultigen',
        'display_name_helper' => 'Shown in the browser title, sign-in pages, and security notifications.',
        'vault_creation_policy' => 'Who can create vaults',
        'vault_creation_policy_helper' => '“Vault creator” is a platform role that grants no access to existing secrets.',
        'vault_creation_policy_options' => [
            'admins' => 'Super Admins only',
            'vault_creators' => 'Super Admins and Vault creators',
            'any_active_user' => 'Any active user',
        ],
        'vault_creation_limit_per_user' => 'Per-creator limit',
        'vault_creation_limit_per_user_helper' => 'Maximum shared vaults one account may create for itself. Super Admins creating vaults in administration or through imports are exempt.',
        'vault_creation_limit_installation' => 'Installation limit',
        'vault_creation_limit_installation_helper' => 'Self-service creation stops once the installation has this many shared vaults. Super Admins creating vaults in administration or through imports are exempt.',
        'inactivity_minutes' => 'Inactivity timeout',
        'inactivity_minutes_helper' => 'Signs the user out after this much idle time.',
        'absolute_minutes' => 'Absolute session lifetime',
        'absolute_minutes_helper' => 'Maximum session age regardless of activity, up to 12 hours.',
        'mfa_methods' => 'Allowed methods',
        'mfa_methods_helper' => 'At least one method must stay allowed; it cannot be turned off. Changing this affects every account and is audited.',
        'mfa_methods_options' => [
            'app' => 'Authenticator app',
            'email' => 'Email code',
        ],
        'remember_device_days' => 'Remember this device',
        'remember_device_days_helper' => 'How long a trusted device may skip the code step after a full sign-in. 0 hides the option; a recovered or reset account always forgets trusted devices.',
        'trash_retention_days' => 'Trash retention',
        'trash_retention_days_helper' => 'Days a deleted entry or folder stays restorable before the purge job may remove it permanently.',
        'vault_purge_retention_days' => 'Vault purge waiting period',
        'vault_purge_retention_days_helper' => 'Days a vault marked for purge can still be restored by a Super Admin. Nobody can access it during this time; afterwards it is deleted permanently and automatically.',
        'vault_purge_notifications_enabled' => 'Email when a vault is marked for purge',
        'vault_purge_notifications_enabled_helper' => 'Sends an alert to the recipients above, who can have a Super Admin restore the vault before it is deleted.',
        'revision_retention_count' => 'Revision count limit',
        'revision_retention_count_helper' => 'Keep at most this many revisions per entry. 0 keeps every revision.',
        'revision_retention_days' => 'Revision age limit',
        'revision_retention_days_helper' => 'Remove revisions older than this many days. 0 disables the age limit.',
        'audit_retention_days' => 'Audit retention',
        'audit_retention_days_helper' => 'Days security events are kept before the controlled prune job may remove them. The surviving chain still verifies from its recorded anchor.',
        'audit_ip_retention_days' => 'IP and user-agent retention',
        'audit_ip_retention_days_helper' => 'Shorter privacy retention for IP addresses and user agents. 0 keeps them for as long as the event; they are never part of the hash chain.',
        'import_retention_days' => 'Import retention',
        'import_retention_days_helper' => 'Days a staged upload or finished import run is kept. Cleanup cancels abandoned uploads and deletes older runs.',
        'site_icons_enabled' => 'Fetch site icons',
        'site_icons_enabled_helper' => 'When on, saving or importing a login with a URL queues a background fetch of the site icon. Turning it off stops new fetches; icons already fetched stay visible.',
        'suffix_minutes' => 'minutes',
        'suffix_days' => 'days',
    ],

    'security_recipients' => [
        'email_placeholder' => 'security@example.com',
        'duplicate' => 'Each recipient must be unique.',
        'add_action' => 'Add recipient',
        'helper' => 'Recovery notifications also go to the affected administrator.',
    ],

    'notifications' => [
        'saved' => 'Settings saved',
    ],

];
