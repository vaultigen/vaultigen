<?php

return [

    'title' => 'Import',
    'subheading' => 'Bring credentials from another password manager into new vaults.',

    'sources' => [
        'syspass_xml' => 'sysPass (encrypted XML export)',
        'keepass_xml' => 'KeePass / KeePassXC (XML export)',
    ],

    'statuses' => [
        'staging' => 'Staging',
        'staged' => 'Ready to review',
        'queued' => 'Waiting for worker',
        'importing' => 'Importing',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ],

    'columns' => [
        'source' => 'Source',
        'file' => 'File',
        'result' => 'Result',
        'requested_by' => 'Requested by',
        'created' => 'Created',
    ],

    'values' => [
        'none' => '—',
    ],

    'actions' => [
        'review' => 'Review and import',
        'report' => 'View report',
        'cancel' => 'Cancel',
    ],

    'filters' => [
        'status' => 'Status',
    ],

    'empty' => [
        'heading' => 'No imports yet',
        'description' => 'Upload an export from another password manager to start.',
    ],

    'upload' => [
        'heading' => 'New import',
        'description' => 'The export is read once (and decrypted with its export password where the source has one), re-encrypted under an installation-wrapped import key, and reviewed before anything is written.',
        'no_sources' => 'No import source is registered.',
        'password_hint' => 'Used only for this upload; it is never stored.',
        'submit' => 'Stage upload',
        'hints' => [
            'keepass_xml' => 'KeePass: File → Export → KeePass XML (2.x). KeePassXC: Database → Export → XML. The XML file is not encrypted: delete it once the import is done.',
        ],
    ],

    'runs' => [
        'heading' => 'Import history',
        'description' => 'Past and in-progress imports. Counters and failures only; no secret values are stored.',
    ],

    'fields' => [
        'source' => 'Source',
        'file' => 'Export file',
        'export_password' => 'Export password',
        'layout' => 'Layout',
        'folder_axis' => 'Folder axis',
        'axis_destination' => ':axis destination',
        'single_vault_name' => 'Vault name',
        'vault_name' => 'Vault name',
        'custodian' => 'Custodian',
        'manager' => 'Manager',
        'default_custodian' => 'Default Custodian',
        'default_manager' => 'Default Manager',
        'entry_count' => 'Entries',
        'vault_count' => 'Vaults',
        'include' => 'Import',
        'tag_name' => 'Tag',
        'tag_scope' => 'Scope',
        'principal' => 'User or team',
        'permission' => 'Role',
        'master_password' => 'Master password',
        'entry_title' => 'Entry',
        'context' => 'Context',
        'reason' => 'Reason',
    ],

    'destinations' => [
        'vault' => 'A separate vault',
        'folder' => 'A folder',
        'tag' => 'A tag',
        'skip' => 'Skip',
    ],

    'axes' => [
        'client' => 'Client',
        'category' => 'Category',
        'top_group' => 'Top-level group',
        'subgroup' => 'Subgroup',
        'group_path' => 'Group path',
        'none' => 'None',
    ],

    'permissions' => [
        'viewer' => 'Viewer',
        'editor' => 'Editor',
        'manager' => 'Manager',
    ],

    'wizard' => [
        'title' => 'Review import',
        'subheading' => 'Nothing is written until you confirm the import.',
        'steps' => [
            'setup' => 'Setup',
            'vaults' => 'Vaults',
            'tags' => 'Tags',
            'confirm' => 'Confirm and import',
        ],
        'step_descriptions' => [
            'setup' => 'What the export contains, how it maps into the vault, and who else gets access.',
            'vaults' => 'Name each new vault, choose its Custodian and Manager, and uncheck any vault to skip it.',
            'tags' => 'Whether each tag is global or vault-local, and which tags to import at all.',
            'confirm' => 'Enter any password the source needs and start the import. Nothing is written before this step.',
        ],
        'sections' => [
            'summary' => 'What will be imported',
            'summary_description' => 'A quick overview of the export. Counts only; no secret values.',
            'layout' => 'Layout and axes',
            'layout_description' => 'How the groupings of the export map to vaults, folders, tags, and skips.',
            'access' => 'Additional access',
            'access_description' => 'Default Custodian and Manager, plus extra users or teams, applied to every vault this import creates.',
        ],
        'save_plan' => 'Save plan',
    ],

    'confirm' => [
        'heading' => 'Confirm and import',
        'description' => 'The import is queued and runs in the background; watch its progress on the next screen. It cannot be resumed once it finishes.',
        'hints' => [
            'master_password' => 'Wrapped with the installation key for the background job and removed when the import ends. Leave blank if this export has no separate master password.',
        ],
        'no_credentials' => 'This source needs no password. The import starts as soon as you run it.',
        'submit' => 'Run import',
    ],

    'notifications' => [
        'plan_saved' => 'Import plan saved',
        'plan_invalid' => 'The import plan is not valid',
        'cancelled' => 'Import cancelled and staging removed',
        'cancel_requested' => 'Cancellation requested; the import will stop shortly',
        'not_cancelled' => 'The import could not be cancelled',
    ],

    'cancel' => [
        'description' => 'This removes the encrypted staging upload and marks the run cancelled. Created vaults, if any, are not touched.',
    ],

    'access' => [
        'add' => 'Add access',
        'helper' => 'Add extra users or teams and pick the role each should have on every vault this import creates (viewer, editor, or manager). The Custodian and Manager chosen per vault in the Vaults step are left unchanged.',
        'default_custodian_helper' => 'Becomes the Custodian of every created vault. Override it per vault in the Vaults step.',
        'default_manager_helper' => 'Becomes the Manager of every created vault. Override it per vault in the Vaults step.',
    ],

    'vaults' => [
        'helper' => 'Unchecking a vault skips it and every entry that would go into it. It is never created.',
        'empty_heading' => 'No vaults to review',
        'empty_description' => 'The import plan does not create any vaults yet.',
    ],

    'tags' => [
        'helper' => 'A tag used in more than one created vault defaults to global. Global tags are reused by name. Unchecking a tag skips it entirely.',
        'empty_heading' => 'No tags to review',
        'empty_description' => 'The export does not contain any tags for the current plan.',
    ],

    'review' => [
        'file' => 'File',
        'generator' => 'Generator',
        'accounts' => 'Accounts',
        'clients' => 'Clients',
        'categories' => 'Categories',
        'tags' => 'Tags',
        'empty_passwords' => 'Empty passwords',
        'entries' => 'Entries',
        'groups' => 'Groups',
        'attachments' => 'Attachments',
        'totp' => 'TOTP seeds',
        'recycle_bin' => 'Skipped: recycle bin',
        'history' => 'Skipped: history',
        'totp_unsupported' => 'Unsupported TOTP',
        'field_references' => 'Field references',
        'invalid_attachments' => 'Unreadable attachments',
    ],

    'result' => ':entries entries in :vaults vaults, :failures failed',

    'report' => [
        'title' => 'Import report',
        'subheading' => 'Counters, failures, and the vaults this run created. No secret values are shown.',
        'back' => 'Back to imports',
        'summary' => ':entries entries imported into :vaults vaults; :failures failed.',
        'reason' => 'Reason',
        'counters' => [
            'vaults' => 'Vaults',
            'folders' => 'Folders',
            'tags' => 'Tags',
            'entries' => 'Entries',
            'grants' => 'Grants',
            'attachments' => 'Attachments',
        ],
        'attachment_summary' => ':attachments attachments imported; :missing attachments missing. Add the missing files to the linked entries by hand.',
        'attachment_position' => 'Attachment :index of :of',
        'vaults_heading' => 'Created vaults',
        'failures_heading' => 'Failed entries and attachments',
        'failures_truncated' => 'Only the first stored failures are listed; the count above is exact.',
        'continue' => 'Continue reviewing this import',
        'unknown_vault' => 'Unknown vault',
        'progress' => ':done of :total entries processed',
        'queue_unavailable_heading' => 'Waiting for a queue worker',
        'queue_unavailable' => 'This import is queued but no queue worker has picked it up yet. Start the queue worker, or the run will be failed automatically.',
    ],

    'context_labels' => [
        'syspass_xml' => 'Customer',
        'keepass_xml' => 'Group',
    ],

    'defaults' => [
        'single_vault_name' => 'Imported passwords',
    ],

    'placeholders' => [
        'untitled' => 'Untitled',
        'untitled_entry' => 'Untitled entry',
    ],

    'layouts' => [
        'vault_per_client' => 'One vault per client',
        'vault_per_category' => 'One vault per category',
        'vault_per_top_group' => 'One vault per top-level group',
        'vault_per_subgroup' => 'One vault per subgroup',
        'vault_per_group_path' => 'One vault per group',
        'single_vault' => 'A single vault',
    ],

    'scopes' => [
        'global' => 'Global',
        'local' => 'Vault-local',
        'skip' => 'Skip',
    ],

    'failure_codes' => [
        'password_decrypt_failed' => 'The account password could not be decrypted.',
        'title_too_long' => 'The title is too long.',
        'url_too_long' => 'The URL is too long.',
        'notes_too_long' => 'The notes are too long.',
        'too_many_custom_fields' => 'The entry has too many custom fields.',
        'custom_field_too_long' => 'A custom field label or value is too long.',
        'invalid_base64' => 'The attachment data is not valid base64.',
        'decompression_failed' => 'The attachment could not be decompressed.',
        'too_large' => 'The attachment is larger than the per-file limit.',
        'missing_reference' => 'The attachment data is missing from the export.',
        'attachment_rejected' => 'The attachment was rejected (empty, too large, or over the vault quota).',
        'attachment_failed' => 'The attachment could not be stored.',
        'folder_name_invalid' => 'The destination folder could not be created.',
        'vault_create_failed' => 'The vault could not be created.',
        'staging_unavailable' => 'The staged upload is unavailable.',
        'master_password_rejected' => 'The master password was rejected; nothing was imported.',
        'queue_failed' => 'The background import job failed.',
        'queue_unavailable' => 'No queue worker processed this import in time.',
        'import_timeout' => 'The import job did not finish in time.',
        'unknown' => 'The entry could not be imported.',
    ],

    'validation' => [
        'distinct_axes' => 'The vault and folder axes must be different.',
        'layout_axis_mismatch' => 'The vault axis does not match the selected layout.',
        'axis_destination' => 'That destination is not available for the :axis axis.',
        'excluded_axes' => 'These axes cannot be combined.',
        'single_vault_name_required' => 'Enter a name for the vault.',
    ],

    'errors' => [
        'unreadable_export' => 'The export could not be read. Check the export password and file format.',
        'unreadable_keepass' => 'The file is not a KeePass 2.x or KeePassXC XML export.',
        'protected_values' => 'The file contains encrypted (protected) values. Export it from KeePass or KeePassXC as plain XML instead.',
        'attachments_too_large' => 'The attachments in this export exceed the import limit. Nothing was staged.',
        'upload_failed' => 'The upload failed. Try again.',
        'too_large' => 'The export is too large to import.',
        'credential_required' => 'Enter the required password.',
        'already_finished' => 'This import has already finished.',
    ],

];
