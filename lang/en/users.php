<?php

return [

    'subheading' => 'Manage accounts and invitations. Platform roles grant no secret access. Every account receives an organization-managed personal work vault.',

    'columns' => [
        'platform_roles' => 'Platform roles',
        'active' => 'Active',
        'verified' => 'Verified',
        'mfa' => 'MFA',
        'registered' => 'Registered',
    ],

    'placeholder' => [
        'ordinary_user' => 'Ordinary user',
    ],

    'filters' => [
        'active' => 'Active',
        'email_verified' => 'Email verified',
        'mfa_method' => 'MFA method',
        'platform_role' => 'Platform role',
        'mfa_options' => [
            'app' => 'App',
            'email' => 'Email',
            'none' => 'None',
        ],
        'platform_role_options' => [
            'super_admin' => 'Super Admin',
            'auditor' => 'Auditor',
        ],
    ],

    'mfa' => [
        'app' => 'App',
        'email' => 'Email',
        'none' => 'None',
        'app_email' => 'App + Email',
    ],

    'actions' => [
        'user_actions' => 'User actions',
        'activate_selected' => 'Activate selected',
        'deactivate_selected' => 'Deactivate selected',
        'deactivate_description' => 'Existing sessions and remember tokens will be revoked.',
        'offboard' => 'Offboard user',
        'offboard_description' => 'Open the offboarding screen with this account preselected.',
        'invite' => 'Invite user',
        'invite_heading' => 'Invite a user',
        'invite_description' => 'Vaultigen emails a single-use link to this address. The recipient verifies their email, chooses a password, and enrolls an authenticator before signing in. The link expires after 24 hours.',
        'invite_submit' => 'Send invitation',
        'email' => 'Email address',
        'email_helper' => 'Inviting an address again replaces any previous pending invitation.',
        'platform_roles' => 'Platform roles',
        'roles_description' => 'Platform roles never grant access to existing secrets. Vault creator only allows creating new vaults.',
        'roles_options' => [
            'super_admin' => 'Super Admin',
            'auditor' => 'Auditor',
            'vault_creator' => 'Vault creator (create vaults, no secret access)',
        ],
        'reset_authentication' => 'Reset authentication',
        'reset_description' => 'Revokes all sessions, remembered devices, and recovery codes. The user must enroll an allowed method again. A security notification is recorded for delivery.',
        'reason_helper' => 'Do not include passwords or other secrets.',
        'your_password' => 'Your password',
        'your_code' => 'Your fresh authenticator code',
    ],

    'empty' => [
        'heading' => 'No users yet',
        'description' => 'Invite the first user to send them a single-use onboarding link.',
    ],

    'notifications' => [
        'invitation_email_failed_title' => 'Invitation created, but the email was not sent',
        'invitation_email_failed_body' => 'The invitation for :email exists. Check the mail transport configuration and use Resend from the invitations list.',
        'invitation_sent_title' => 'Invitation sent',
        'invitation_sent_body' => 'A single-use link was sent to :email. It expires in 24 hours.',
    ],

    'blade' => [
        'recent_invitations' => 'Recent invitations',
        'recent_invitations_description' => 'Invitations expire 24 hours after they are sent and can be accepted once.',
        'delivery' => 'Security notification delivery',
        'delivery_description' => 'The scheduler retries pending notifications automatically.',
        'attempts' => 'Attempts: :count',
        'delivered' => 'Delivered',
        'failed' => 'Failed',
        'pending' => 'Pending',
        'none_pending' => 'No security notifications pending.',
    ],

    'invitations' => [
        'email' => 'Email',
        'invited_by' => 'Invited by',
        'removed_account' => 'Removed account',
        'created' => 'Created',
        'expires' => 'Expires',
        'status' => [
            'pending' => 'Pending',
            'accepted' => 'Accepted',
            'expired' => 'Expired',
            'cancelled' => 'Cancelled',
        ],
        'delivery' => [
            'sent' => 'Sent',
            'pending' => 'Pending',
            'failed' => 'Failed',
        ],
        'delivery_state' => [
            'sent' => 'Sent',
            'pending' => 'Pending',
            'failed' => 'Delivery failed',
            'not_configured' => 'Mail not configured',
        ],
        'resend' => 'Resend',
        'resend_description' => 'A fresh single-use link will be emailed. The previous link stops working immediately.',
        'cancel' => 'Cancel',
        'cancel_description' => 'The invitation link stops working immediately. The recipient cannot accept it afterwards.',
        'empty_heading' => 'No invitations yet',
        'empty_description' => 'Invite a user from the Users table above to send them a single-use link.',
        'replaced_email_failed_title' => 'Invitation replaced, but the email was not sent',
        'replaced_email_failed_body' => 'The link for :email was replaced. Check the mail transport and resend again from this list.',
        'resent_title' => 'Invitation resent',
        'resent_body' => 'A fresh single-use link was sent to :email.',
        'cancelled_title' => 'Invitation cancelled',
        'cancelled_body' => 'The link for :email no longer works.',
    ],

    'administration' => [
        'cannot_deactivate_self' => 'Another administrator must deactivate your account.',
        'cannot_remove_own_admin' => 'Another administrator must remove your administrator role.',
        'use_account_security' => 'Use account security to manage your own factors.',
        'invitation_unavailable' => 'This invitation is invalid or no longer available.',
    ],

];
