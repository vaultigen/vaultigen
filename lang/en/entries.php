<?php

return [

    'types' => [
        'login' => 'Login',
        'note' => 'Secure note',
        'ssh_key' => 'SSH key',
        'totp' => 'TOTP',
    ],

    'fields' => [
        'login' => [
            'username' => 'Username',
            'password' => 'Password',
            'url' => 'Full URL',
            'notes' => 'Notes',
            'totp_secret' => 'Authenticator seed',
        ],
        'note' => [
            'body' => 'Note',
        ],
        'ssh_key' => [
            'public_key' => 'Public key',
            'private_key' => 'Private key',
            'passphrase' => 'Passphrase',
            'notes' => 'Notes',
        ],
        'totp' => [
            'issuer' => 'Issuer',
            'account' => 'Account',
            'secret' => 'Secret',
            'algorithm' => 'Algorithm',
            'digits' => 'Digits',
            'period' => 'Period',
            'notes' => 'Notes',
        ],
    ],

    'common' => [
        'owner' => 'Owner',
        'folder' => 'Folder',
        'tags' => 'Tags',
        'no_folder' => 'No folder',
        'unknown' => 'Unknown',
        'updated' => 'Updated',
    ],

    'state' => [
        'read_only' => [
            'heading' => 'This vault is read-only',
            'description' => 'Entries, folders, tags, and membership cannot be changed while the vault is read-only. Everything remains readable and revealable.',
        ],
        'archived' => [
            'heading' => 'This vault is archived',
            'description' => 'An archived vault is frozen. Reactivate it from vault governance before making changes; reading and reveal still work.',
        ],
        'pending_purge' => [
            'heading' => 'This vault is pending purge',
            'description' => 'The vault is frozen and marked for permanent removal. Reading and reveal still work until an administrator confirms the purge.',
        ],
    ],

    'validation' => [
        'choose_supported_type' => 'Choose a supported entry type.',
        'provide_key' => 'Provide at least a public key or a private key.',
        'entry_too_large' => 'This entry is too large to store. Shorten the notes or custom values.',
        'invalid_totp_secret' => 'Enter a valid Base32 authenticator secret (at least 80 bits).',
        'too_many_custom_fields' => 'Too many custom fields (maximum :max).',
        'custom_label_too_long' => 'Custom field labels are limited to :max characters.',
        'custom_value_too_long' => 'Custom field values are limited to :max characters.',
        'folder_not_in_vault' => 'Choose a folder that belongs to this vault.',
        'vault_not_active' => 'This vault is not active, so its entries cannot be changed.',
        'stale_entry' => 'This entry changed since you opened it. Reload and apply your changes again.',
        'owner_not_allowed' => 'Choose an owner who has access to this vault.',
    ],

    'errors' => [
        'update_not_allowed' => 'You are not allowed to update this entry.',
        'folder_placement_not_allowed' => 'You are not allowed to place an entry in that folder.',
        'move_not_allowed' => 'You are not allowed to move this entry.',
        'move_exposes_history' => 'This move would expose the entry’s version history, which only a Manager may do. Ask a Manager of this entry to move it.',
        'destination_placement_not_allowed' => 'You are not allowed to place an entry in the destination.',
        'history_not_allowed' => 'You are not allowed to view this entry’s history.',
        'restore_not_allowed' => 'You are not allowed to restore this entry.',
        'trash_restore_not_allowed' => 'You are not allowed to restore this entry from trash.',
        'delete_not_allowed' => 'You are not allowed to delete this entry.',
        'folder_create_manager_only' => 'Only a vault Manager can create a folder here.',
        'create_in_folder_not_allowed' => 'You are not allowed to create an entry in that folder.',
    ],

    'js' => [
        'copy_failed' => 'Could not copy to the clipboard',
        'copied_to_clipboard' => 'Copied to clipboard',
        'copied_label' => ':label copied',
        'none_stored' => 'No :label stored',
        'code_copied' => 'Code copied',
        'not_available' => 'Not available',
        'show' => 'Show',
        'hide' => 'Hide',
        'show_password' => 'Show password',
        'hide_password' => 'Hide password',
        'password_start' => 'Start typing',
        'password_select_set' => 'Select at least one character set',
        'password_weak' => 'Weak',
        'password_fair' => 'Fair',
        'password_good' => 'Good',
        'password_strong' => 'Strong',
        'leave_blank' => 'Leave blank to keep current',
    ],

    'form' => [
        'title' => [
            'edit' => 'Edit :type',
            'new' => 'New :type',
        ],
        'heading' => [
            'edit' => 'Edit :type entry',
            'new' => 'New entry',
            'description' => 'Only the title, entry type, sanitized login host, owner, expiry, and folder/tag placement are stored in the clear. Every other value is encrypted before it is written.',
        ],
        'section' => [
            'details' => 'Entry details',
            'organization' => 'Organization',
            'totp' => 'Authenticator parameters',
        ],
        'entry_type' => [
            'label' => 'Entry type',
            'helper' => 'The type is fixed after creation. Switching type now clears the values you have entered.',
        ],
        'owner' => [
            'helper' => 'Only people with access to this vault can own an entry.',
        ],
        'expires_at' => [
            'label' => 'Expires at (optional)',
        ],
        'organization' => [
            'description' => 'Folders and tags are metadata. They are stored in the clear and support search and grouping; they never change who can reveal the secret.',
        ],
        'folder' => [
            'helper_scoped' => 'You can place this entry at the vault root or in any folder.',
            'helper_unscoped' => 'Choose one of the folders you have access to. Root placement needs vault-scope access.',
            'root_option' => 'No folder (vault root)',
        ],
        'new_folder' => [
            'label' => 'Create a new folder (optional)',
            'helper' => 'If filled, the folder is created and this entry is placed inside it.',
        ],
        'tags' => [
            'placeholder' => 'Add a tag',
            'helper' => 'Pick an existing tag or type a new name. New tags are saved to this vault.',
        ],
        'totp' => [
            'description' => 'Non-secret settings used to generate codes for a TOTP entry.',
        ],
        'algorithm' => [
            'label' => 'Algorithm',
        ],
        'digits' => [
            'label' => 'Digits',
        ],
        'period' => [
            'label' => 'Period (seconds)',
        ],
        'title_field' => [
            'label' => 'Title',
        ],
        'secret_note' => 'Leave blank to keep the current value, or type a replacement. Secret fields are never pre-filled.',
        'username' => [
            'label' => 'Username',
        ],
        'url' => [
            'label' => 'Full URL',
            'hint' => 'The complete URL stays encrypted; only its host is shown in lists.',
        ],
        'password' => [
            'label' => 'Password',
            'generate' => 'Generate',
            'length' => 'Length',
            'symbols' => 'symbols',
            'bits_of_entropy' => 'bits of entropy',
        ],
        'notes' => [
            'label' => 'Notes',
        ],
        'totp_seed' => [
            'label' => 'Authenticator seed (optional)',
            'placeholder_saved' => 'A seed is saved — leave blank to keep it',
            'placeholder_new' => 'Base32 secret, e.g. JBSWY3DPEHPK3PXP',
            'hint' => 'Optional. The entry page will show the current one-time code for this account.',
        ],
        'totp_warning' => [
            'heading' => 'Storing a password and its second factor together reduces separation of factors',
            'description' => 'For high-value accounts, keep the password and the authenticator seed under separate access by placing the seed in its own vault rather than alongside the password.',
        ],
        'note' => [
            'label' => 'Note',
        ],
        'public_key' => [
            'label' => 'Public key',
        ],
        'private_key' => [
            'label' => 'Private key',
            'hidden' => 'Hidden — reveal to edit',
        ],
        'ssh_key' => [
            'hint' => 'Provide at least a public key or a private key. Private keys are masked by default.',
        ],
        'passphrase' => [
            'label' => 'Passphrase',
        ],
        'issuer' => [
            'label' => 'Issuer',
        ],
        'account' => [
            'label' => 'Account',
        ],
        'secret' => [
            'label' => 'Secret',
            'hint' => 'Base32 secret from the authenticator. It is encrypted and shown only when revealed.',
        ],
        'custom_fields' => [
            'heading' => 'Custom fields',
            'description' => 'Every custom value is encrypted. Concealed values stay hidden until revealed; visible values are shown on the entry page.',
            'label' => 'Label',
            'value' => 'Value',
            'concealed' => 'Concealed',
            'remove' => 'Remove',
            'add' => 'Add custom field',
        ],
        'edit_notice' => 'Secret fields are intentionally left blank. Leave them empty to keep the current values, or type a replacement.',
        'save_changes' => 'Save changes',
        'create_entry' => 'Create entry',
        'cancel' => 'Cancel',
    ],

    'detail' => [
        'action' => [
            'entries' => 'Entries',
            'edit' => 'Edit',
            'move' => 'Move',
            'access' => 'Additional access',
            'delete' => 'Delete',
            'refresh_icon' => 'Refresh icon',
        ],
        'delete_confirm' => 'Move this entry to the trash? It can be restored until its purge deadline, then removed permanently.',
        'deleted' => 'Entry moved to trash',
        'icon_refresh_queued' => 'Icon refresh queued',
        'not_deleted' => 'Entry not deleted',
        'custom_field' => 'Custom field',
        'concealed' => 'Concealed',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'reveal' => 'Reveal',
        'restored' => [
            'title' => 'Revision restored',
            'body' => 'The previous values are back, and the replaced state is kept in history.',
        ],
        'key_values' => [
            'heading' => 'Stored values',
            'description' => 'Each field is delivered on its own only after a fresh permission check and an audit record. Revealed values are cleared from the page after 30 seconds.',
        ],
        'reveal_unavailable' => [
            'heading' => 'Reveal is not available',
            'description' => 'Your access to this vault does not allow revealing secret values.',
        ],
        'totp' => [
            'heading' => 'Authenticator code',
            'description' => 'The current one-time code is generated from the stored seed and audited each time it is delivered.',
            'show' => 'Show current code',
            'refreshing' => 'Refreshing…',
            'error' => 'The code could not be generated. Check the stored seed.',
            'seconds_remaining' => 's remaining',
        ],
        'expiry' => 'Expiry',
        'no_expiry' => 'No expiry',
        'expired' => 'Expired :time',
        'access' => [
            'heading' => 'Access',
            'description' => 'Access is additive. A vault, folder, or entry grant can each add reach; none of them can reduce another.',
            'can_edit_heading' => 'You can change this entry',
            'can_edit_description' => 'Your grant on this vault lets you edit every stored value.',
            'read_only_heading' => 'Read-only access',
            'read_only_description' => 'Your grant lets you view this entry, but not change it.',
        ],
        'managers_only' => 'Only Managers can view history and restore revisions.',
        'history' => [
            'heading' => 'History',
            'description' => 'Every update stores the previous encrypted document. Restoring keeps the replaced state in history and records the change.',
            'empty_heading' => 'No revisions yet',
            'empty_description' => 'This entry has not been changed since it was created.',
            'revision' => 'Revision :number',
            'restore' => 'Restore',
            'restore_confirm' => 'Restore this revision? The current values are kept in history.',
        ],
    ],

    'move' => [
        'title' => 'Move entry',
        'subheading' => 'Access changes immediately after a move. Additional grants are re-checked against the destination.',
        'section' => [
            'destination' => 'Destination',
            'access' => 'Access impact',
        ],
        'destination' => [
            'description' => 'Choose where the entry should live. You can only move it where you are allowed to create entries.',
        ],
        'vault' => [
            'label' => 'Vault',
            'helper' => 'Moving across vaults re-encrypts the entry under the destination vault key.',
        ],
        'new_folder' => [
            'label' => 'Or create a new folder',
            'helper' => 'A vault Manager may create the folder as part of the move.',
        ],
        'impact' => [
            'destination' => 'People with a vault or folder grant on the destination gain access immediately.',
            'lose_access' => 'People who only reached this entry through the old vault, folder, or an entry grant lose access.',
            'cross_vault' => 'A cross-vault move clears the vault-scoped tag labels, makes you the owner, and shows the version history to the destination vault’s Managers.',
        ],
        'not_moved' => 'Entry not moved',
        'moved' => 'Entry moved',
        'submit' => 'Move entry',
        'cancel' => 'Cancel',
        'confirm' => 'Move this entry? People with access to the destination gain access immediately, and people who only reached it through the old vault, folder, or an entry grant lose access.',
    ],

    'list' => [
        'subheading' => 'Entries · search matches title, host, type, folder, and tag names. Usernames, passwords, and notes stay encrypted and are never searched.',
        'action' => [
            'my_vaults' => 'My vaults',
            'new_entry' => 'New entry',
            'about' => 'About this vault',
            'close' => 'Close',
            'manage_folders' => 'Manage folders',
            'manage_tags' => 'Manage tags',
            'trash' => 'Trash',
            'edit_vault' => 'Edit vault',
            'manage_members' => 'Manage members',
            'vault_actions' => 'Vault actions',
            'copy_username' => 'Copy username',
            'copy_password' => 'Copy password',
            'copy_passphrase' => 'Copy passphrase',
            'open' => 'Open',
        ],
        'no_icon' => 'No icon',
        'vault_updated' => 'Vault updated',
        'column' => [
            'host' => 'Host',
            'expires' => 'Expires',
        ],
        'placeholder' => [
            'no_expiry' => 'No expiry',
        ],
        'filter' => [
            'tag' => 'Tag',
        ],
        'search_placeholder' => 'Search title, host, type, folder, tag',
        'empty' => [
            'heading' => 'No entries here yet',
            'description' => 'No entries match this view. Search only looks at metadata, never at encrypted values.',
        ],
    ],

];
