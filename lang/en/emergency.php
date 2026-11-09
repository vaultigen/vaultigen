<?php

return [

    'title' => 'Emergency access',
    'navigation_label' => 'Emergency access',
    'subheading' => 'Time-limited, read-only inspection of one vault for an operational incident. Every session is prominently audited and notified.',
    'unknown_vault' => 'Unknown vault',
    'unknown_actor' => 'Unknown administrator',

    'actions' => [
        'start' => 'Start emergency access',
        'end' => 'End access',
        'start_modal_heading' => 'Start emergency access',
        'start_modal_description' => 'Re-authenticate to start a read-only session. It expires automatically after 30 minutes and cannot edit, share, move, delete, or export.',
    ],

    'fields' => [
        'vault' => 'Vault',
        'reason' => 'Reason',
        'reason_helper' => 'Required. Recorded in the audit log and notifications.',
        'incident_reference' => 'Incident / ticket reference',
        'incident_reference_helper' => 'Optional. Recorded in the audit log.',
        'password' => 'Your password',
        'code' => 'Your authentication code',
        'expires_at' => 'Expires at',
    ],

    'values' => [
        'none' => '—',
        'active' => 'Active',
    ],

    'active' => [
        'heading' => 'Emergency access is active',
        'description' => 'You hold a read-only emergency session on this vault.',
        'scope_notice' => 'Metadata view and individual reveal only. Editing, sharing, moving, deleting, and exporting remain blocked, and the session ends automatically at expiry.',
    ],

    'recent' => [
        'heading' => 'Recent emergency sessions',
        'description' => 'The latest sessions, including closed and expired ones.',
        'empty' => 'No emergency access has been started.',
    ],

    'columns' => [
        'vault' => 'Vault',
        'actor' => 'Administrator',
        'reason' => 'Reason',
        'started_at' => 'Started (UTC)',
        'ended_at' => 'Ended (UTC)',
        'notification_status' => 'Notifications',
    ],

    'statuses' => [
        'pending' => 'Pending',
        'sent' => 'Sent',
        'failed' => 'Failed',
        'none' => 'No recipients',
    ],

    'empty' => 'No active emergency session.',

    'banner' => [
        'title' => 'Emergency access active.',
        'body' => 'Read-only access to :vault until :expires UTC.',
        'end_confirm' => 'End emergency access now?',
    ],

    'notifications' => [
        'started' => 'Emergency access started',
        'ended' => 'Emergency access ended',
        'failed' => 'Emergency access could not be started',
    ],

];
