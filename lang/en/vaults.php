<?php

return [

    'model' => [
        'label' => 'vault',
        'plural' => 'vaults',
    ],

    'actions' => [
        'new_vault' => 'New vault',
        'create_vault' => 'Create vault',
        'vault_administration' => 'Vault administration',
        'open' => 'Open',
        'edit' => 'Edit',
        'edit_vault' => 'Edit vault',
        'more_actions' => 'More actions',
        'add_grant' => 'Add grant',
        'add_or_replace_grant' => 'Add or replace grant',
        'save_grant' => 'Save grant',
        'revoke' => 'Revoke',
        'transfer_custodian' => 'Transfer custodian',
        'archive' => 'Archive',
        'reactivate' => 'Reactivate',
        'initiate_purge' => 'Initiate purge',
        'cancel_purge' => 'Restore vault',
        'recover_manager' => 'Recover Manager',
    ],

    'labels' => [
        'vault' => 'Vault',
        'type' => 'Type',
        'owner' => 'Owner',
        'custodian' => 'Custodian',
        'your_access' => 'Your access',
        'access_expires' => 'Access expires',
        'no_expiry' => 'No expiry',
        'no_icon' => 'No icon',
        'image' => 'Image',
        'image_helper' => 'Optional. Shown instead of the icon; resized to a small square avatar.',
        'remove_image' => 'Remove the current image',
        'created' => 'Created',
        'purge_after' => 'Deletion date',
        'expiry' => 'Expiry',
        'expires' => 'Expires',
        'granted_by' => 'Granted by',
        'unknown' => 'Unknown',
        'note' => 'Note',
        'user_or_team' => 'User or team',
        'permission' => 'Permission',
        'expires_at_optional' => 'Expires at (optional)',
    ],

    'kinds' => [
        'personal' => 'Personal work vault',
        'shared' => 'Shared vault',
    ],

    'states' => [
        'active' => 'Active',
        'read_only' => 'Read Only',
        'archived' => 'Archived',
        'pending_purge' => 'Pending Purge',
    ],

    'state_filters' => [
        'active' => 'Active',
        'read_only' => 'Read only',
        'archived' => 'Archived',
        'pending_purge' => 'Pending purge',
    ],

    'permissions' => [
        'viewer' => 'Viewer',
        'editor' => 'Editor',
        'manager' => 'Manager',
    ],

    'permission_options' => [
        'viewer' => 'Viewer (metadata and reveal)',
        'editor' => 'Editor (create and update)',
        'manager' => 'Manager (full control)',
    ],

    'statuses' => [
        'active' => 'Active',
        'history' => 'History',
    ],

    'status_filters' => [
        'active' => 'Active',
        'history' => 'History',
    ],

    'principals' => [
        'user' => 'user',
        'team' => 'team',
        'unknown' => 'unknown',
    ],

    'scopes' => [
        'vault' => 'vault',
        'folder' => 'folder',
        'entry' => 'entry',
    ],

    'principal' => [
        'team_name' => 'Team: :name',
    ],

    'grant_trail' => [
        'expires' => 'expires :date',
        'no_expiry' => 'no expiry',
    ],

    'notifications' => [
        'vault_created' => 'Vault created',
        'vault_updated' => 'Vault updated',
        'vault_archived' => 'Vault archived',
        'vault_reactivated' => 'Vault reactivated',
        'vault_marked_pending_purge' => 'Vault marked pending purge',
        'vault_purge_cancelled' => 'Vault restored',
        'vault_recovered' => 'Vault recovered',
        'custodian_transferred' => 'Custodian transferred',
        'grant_saved' => 'Grant saved',
        'grant_not_saved' => 'Grant not saved',
        'grant_revoked' => 'Grant revoked',
        'grant_not_revoked' => 'Grant not revoked',
        'folder_renamed' => 'Folder renamed',
        'folder_not_renamed' => 'Folder not renamed',
    ],

    'errors' => [
        'invalid_image' => 'The image must be a PNG, JPEG, GIF or WebP file of at most 2 MB and 4096 × 4096 pixels.',
        'already_pending_purge' => 'This vault is already pending purge.',
        'not_pending_purge' => 'This vault is not pending purge.',
        'recover_pending_purge' => 'Restore this vault from pending purge first.',
        'pending_purge_state_change' => 'A vault pending purge cannot change state.',
        'already_in_state' => 'The vault is already in that state.',
        'recovery_not_required' => 'This vault still has an active Manager, so recovery is not required.',
        'not_editable' => 'This vault is not editable in its current state.',
        'not_allowed_to_govern' => 'You are not allowed to govern this vault.',
        'grant_already_inactive' => 'This grant is already inactive.',
        'keep_one_manager' => 'A vault must keep at least one active Manager grant.',
        'manager_membership_only' => 'Only a vault Manager can change membership.',
        'manager_additional_grants_only' => 'Only a vault Manager can change additional grants.',
        'expiry_after_authority' => 'The grant expiry cannot be later than your own access expiry.',
        'membership_vault_inactive' => 'This vault is not active, so its membership cannot be changed.',
        'grants_vault_inactive' => 'This vault is not active, so its grants cannot be changed.',
        'creation_limit_reached' => 'The vault creation limit for this installation has been reached. Ask an administrator to raise the quota.',
    ],

    'my_vaults' => [
        'create_modal' => [
            'heading' => 'Create a vault',
            'description' => 'You become this vault’s Manager and Custodian. A secret that must stay hidden from a vault’s Manager belongs in its own vault.',
            'access_helper' => 'Shown to everyone with access. Do not include secrets.',
        ],
        'created_body' => 'You are its Manager and Custodian. Add members from the vault.',
        'empty' => [
            'heading' => 'No vaults available yet',
            'description' => 'You only see vaults granted to you. A vault Manager must grant access, and every account gets one personal work vault provisioned automatically.',
        ],
    ],

    'list_vaults' => [
        'create_modal' => [
            'heading' => 'Create a vault shell',
            'description' => 'The initial Manager receives a Manager grant. Creating the vault does not grant you access.',
            'metadata_helper' => 'Shown to everyone with metadata access. Do not include secrets.',
        ],
        'fields' => [
            'primary_custodian' => 'Primary custodian',
            'primary_custodian_helper' => 'Accountable for the vault. This role grants no data access by itself.',
            'initial_manager' => 'Initial Manager',
            'initial_manager_helper' => 'Receives an explicit Manager grant when the vault is created.',
        ],
        'created_body' => 'The initial Manager now controls membership and data access.',
    ],

    'infolist' => [
        'sections' => [
            'your_access' => 'Your access',
        ],
    ],

    'vaults_table' => [
        'empty' => [
            'heading' => 'No vaults to administer',
            'description' => 'You are not a Custodian or Manager of a vault and do not administer the installation.',
        ],
        'transfer_modal' => [
            'heading' => 'Transfer custodianship',
            'description' => 'The new Custodian governs this vault’s lifecycle without gaining access to its secrets.',
            'new_custodian' => 'New custodian',
        ],
        'archive_modal' => [
            'description' => 'Archived vaults are frozen: membership and metadata cannot change until reactivated.',
        ],
        'purge_modal' => [
            'description' => 'Nobody can access the vault from now on. A Super Admin can restore it until the waiting period ends; then it is deleted permanently.',
        ],
        'cancel_purge_modal' => [
            'description' => 'Returns the vault to its previous state. Its contents and access are unchanged.',
        ],
        'recover_modal' => [
            'heading' => 'Recover an unmanaged vault',
            'description' => 'Use only when a vault has no active Manager. This is strongly re-authenticated, requires a reason, and is prominently audited.',
            'new_manager' => 'New Manager',
            'reason_helper' => 'Do not include passwords or other secrets.',
            'your_password' => 'Your password',
            'your_code' => 'Your fresh authenticator code',
        ],
    ],

    'members' => [
        'title' => 'Members',
        'empty' => [
            'heading' => 'No grants yet',
            'description' => 'Add a user or team to give them access to this vault.',
        ],
        'grant_modal' => [
            'heading' => 'Grant vault access',
            'replace_description' => 'Saving a grant for a principal that already has one replaces it and keeps the previous grant in history.',
            'authority_helper' => 'You cannot grant more than your own effective authority.',
            'expiry_helper' => 'Checked on every request. Cannot be later than your own access expiry.',
        ],
        'revoke_modal' => [
            'description' => 'The principal loses access immediately. History is preserved.',
        ],
    ],

    'scoped_access' => [
        'title' => [
            'folder' => 'Edit folder · ',
            'entry' => 'Additional access · ',
        ],
        'subheading' => [
            'folder' => 'Rename this folder and manage the additional grants that reach the entries inside it.',
            'entry' => 'Grants here are additive. They can add access to this entry, but never reduce access inherited from the vault or its folder.',
        ],
        'back' => [
            'folder' => 'Folders',
            'entry' => 'Entry',
        ],
        'folder_section' => [
            'heading' => 'Folder name',
            'description' => 'Only a vault Manager can rename folders. The name is stored in the clear and is unique within the vault.',
            'save_name' => 'Save name',
        ],
        'vault_access' => [
            'heading' => 'Whole-vault access',
            'description' => 'These people can reach this :kind because they hold a grant on the entire vault. They are not listed in the table above, which only shows grants made directly on this :kind.',
            'empty_heading' => 'No one has whole-vault access',
            'empty_description' => 'No one reaches this :kind through a vault-wide grant. Everyone who can reach it is listed in the table above.',
        ],
        'empty' => [
            'heading' => 'No additional grants',
            'description' => 'Everyone with access reaches this target through a vault or folder grant. Add one only when someone needs it without broader access.',
        ],
        'grant_modal' => [
            'heading' => 'Add or replace an additional grant',
            'replace_description' => 'Saving a grant for a principal that already has one replaces it and keeps the previous grant in history.',
            'authority_helper' => 'You cannot grant more than your own effective authority.',
            'expiry_helper' => 'Checked on every request. Cannot be later than your own access expiry.',
        ],
        'revoke_modal' => [
            'description' => 'The principal loses this additional access immediately. History is preserved.',
        ],
    ],

    'about' => [
        'your_access' => 'Your access: :permission',
        'expires_badge' => 'Expires :date',
        'scoped_access' => 'Scoped access',
        'callout' => [
            'heading' => 'Organization-managed vault',
            'description' => 'Vaultigen is server-trusted: the host operator can access stored secrets. A personal work vault is not private consumer storage and remains subject to organization retention and emergency access.',
        ],
        'why_heading' => 'Why can I see this?',
        'why_description' => 'These active grants give you access. Expired or revoked grants stop working immediately.',
    ],

    'personal_vault' => [
        'name' => ":name's work vault",
        'description' => 'Organization-managed personal work vault. The host operator can access it.',
    ],

];
