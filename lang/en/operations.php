<?php

return [
    'title' => 'Operations',
    'subheading' => 'Backup readiness, recoverability, and key rotation.',

    'tabs' => [
        'backup' => 'Backup',
        'keys' => 'Keys',
    ],

    'actions' => [
        'backup' => 'Run backup',
        'backup_now' => 'Run backup now',
        'rotate_kek' => 'Rewrap installation key',
        'rotate_vault' => 'Rotate a vault key',
        'disabled_not_ready' => 'Resolve the readiness issues above before running a backup.',
        'disabled_busy' => 'Another operation is in progress; wait for it to finish.',
    ],

    'fields' => [
        'vault' => 'Vault',
        'password' => 'Your password',
    ],

    'readiness' => [
        'heading' => 'Backup readiness',
        'description' => 'These checks must pass before a backup can be trusted.',
        'backup_key' => [
            'label' => 'Backup encryption key',
            'ok' => 'A backup keyring is configured and readable.',
            'error' => 'No backup keyring is configured. Run vaultigen:backup-key:generate on the host.',
        ],
        'disk' => [
            'label' => 'Backup storage',
            'ok' => 'The private backup directory is writable.',
            'error' => 'The private backup directory is not writable.',
        ],
        'tool' => [
            'label' => 'Database snapshot tool',
            'ok' => 'The snapshot method for :driver is available.',
            'error' => 'The snapshot method for :driver is not available on this host.',
        ],
        'window' => [
            'label' => 'Maintenance window',
            'ok' => 'No backup or rotation is in progress.',
            'busy' => 'Another operation is running: :operation.',
        ],
        'operations' => [
            'maintenance' => 'a maintenance window',
            'backup' => 'a backup',
            'rotation' => 'a key rotation',
        ],
    ],

    'recovery' => [
        'heading' => 'Recovery inputs',
        'description' => 'A restore needs these inputs from separate escrow. Only their identifiers are shown here; the values are never available in the browser.',
        'columns' => [
            'input' => 'Input',
            'identifier' => 'Identifier',
            'status' => 'Status',
        ],
        'installation_id' => 'Installation ID',
        'kek' => 'Installation KEK keyring',
        'app_key' => 'APP_KEY',
        'passkey_secret' => 'Passkey user-handle secret',
        'audit_key' => 'Audit-integrity key',
        'backup_key' => 'Backup key',
        'notice' => 'Store each input in a different place from the backups and from the live installation. Losing one of them can make a restore impossible.',
    ],

    'backup' => [
        'heading' => 'Latest backup',
        'description' => 'Completion is reported only after every written object has been re-read and verified.',
        'empty' => 'No backup has been taken yet.',
        'state' => 'Result',
        'objects' => 'Encrypted objects',
        'bytes' => 'Total bytes',
        'created_at' => 'Started',
        'finished_at' => 'Finished',
        'schema' => 'Schema',
        'offsite' => 'Copy each backup set to offline or write-protected storage. A backup left next to the live database does not protect against ransomware.',
        'confirm' => 'A backup will pause all writes for a short window while it snapshots the database and copies every referenced encrypted object. Continue?',
    ],

    'key_health' => [
        'heading' => 'Key health',
        'description' => 'Every vault must have an active key, and every key used by a wrapping must still exist in the keyring.',
        'active_kek' => 'Active installation KEK',
        'key_ids' => 'Available KEK IDs',
        'versions' => 'Vault key versions',
        'retired' => ':count retired',
        'problem' => 'Recoverability is at risk. Missing KEK IDs: :keys. Vaults without an active key: :vaults.',
        'ok' => 'Every advertised key is present and every vault has an active key.',
    ],

    'rotation' => [
        'heading' => 'Key rotations',
        'description' => 'An installation rewrap keeps vault keys unchanged; a vault rotation re-encrypts one vault under a new key version.',
        'empty' => 'No key rotation has been run yet.',
        'kek_description' => 'Rewrap every vault key under the active installation KEK. This changes no secret data. Old keys stay in the keyring for retained backups. Confirm with your password and a fresh code.',
        'vault_description' => 'Create a new vault key version for one vault, make it active, and re-encrypt entries, revisions, and attachment file keys under it. A concurrent edit is never overwritten. Confirm with your password and a fresh code.',
        'progress' => ':processed processed, :failed failed',
        'resume' => 'Resume',
        'operations' => [
            'installation_kek_rewrap' => 'Installation key rewrap',
            'vault_key_rotate' => 'Vault key rotation',
        ],
        'columns' => [
            'operation' => 'Operation',
            'status' => 'Status',
            'progress' => 'Processed / failed',
            'created_at' => 'Started',
            'action' => 'Action',
        ],
    ],

    'active' => [
        'heading' => 'Operation in progress',
        'description' => 'The page refreshes automatically while the operation runs.',
        'backup' => 'A backup is running. Writes are paused until it finishes.',
        'rotation' => 'A key rotation is running. The page refreshes automatically.',
    ],

    'maintenance' => [
        'heading' => 'Maintenance window open',
        'description' => 'Writes are paused so the database snapshot and the encrypted objects describe the same moment.',
        'operation' => 'Operation: :operation',
        'write_blocked' => 'The installation is in a maintenance window; writes are paused. Try again shortly.',
    ],

    'states' => [
        'pending' => 'Pending',
        'running' => 'Running',
        'completed' => 'Completed',
        'failed' => 'Failed',
    ],

    'values' => [
        'present' => 'Present',
        'missing' => 'Missing',
        'unknown' => 'Unknown',
        'pending' => 'Not finished',
    ],

    'notifications' => [
        'backup_started' => 'Backup started. It will pause writes briefly while it runs.',
        'backup_not_started' => 'The backup did not start',
        'rotation_started' => 'Key rotation started.',
        'rotation_not_started' => 'The key rotation did not start',
        'rotation_resumed' => 'The key rotation is resuming.',
    ],

    'restore' => [
        'table_header' => 'Backup set',
        'run' => 'Backup run: :id',
        'created' => 'Created: :at',
        'driver' => 'Database driver: :driver',
        'objects' => 'Referenced objects: :count',
        'kek_keys' => 'Required installation KEK IDs: :ids',
        'audit_keys' => 'Required audit key: :ids',
        'confirm' => 'This replaces the current database and required stored files with the backup. Continue?',
    ],

    'errors' => [
        'backup_configuration_invalid' => 'Backup settings are invalid. Check enablement, five-field cron schedule, timezone, age, and retention days.',
        'backup_queue_invalid' => 'Scheduled backups require a durable queue and retry_after longer than the backup timeout. Check the worker and queue configuration.',
        'backup_cleanup_failed' => 'The backup failed and its partial directory could not be removed. Check backup-root permissions and remove the incomplete set.',
        'backup_stale' => 'A backup may be interrupted. Stop services, confirm no operation is alive, then run vaultigen:backup:recover --services-stopped while Laravel is down.',
        'backup_overdue' => 'No recent successful backup exists. Run a backup, check the scheduler and worker, and copy verified sets off-host.',
        'backup_current' => 'A recent completed backup exists.',
        'interrupted' => 'An operator marked this interrupted backup failed after stopping services.',
        'unsafe_path' => 'A backup or storage path is unsafe. Absolute object paths, traversal, and links are refused.',
        'insufficient_space' => 'Insufficient free space for staging and the final backup or restore. Check both the private temporary volume and destination.',
        'unsupported_format' => 'This backup format is not supported by this application version.',
        'database_driver_mismatch' => 'Restore requires the same database engine as the backup.',
        'audit_key_missing' => 'Restore requires the original audit key and matching key identifier.',
        'recovery_input_missing' => 'An original recovery secret is missing. Restore APP_KEY, audit/passkey secrets, and required keyrings.',
        'recovery_input_mismatch' => 'A recovery secret differs from the original. Restore the original inputs; do not generate replacement keys.',
        'restore_requires_offline' => 'Run artisan down, stop web, workers, and scheduler, then pass --services-stopped to restore.',
        'restore_apply_failed' => 'Restore application failed. Keep services offline; storage/framework/restore-in-progress.json marks the partial restore. Retry with the same set or restart on a clean target.',
        'restore_staging_failed' => 'Restore staging failed before database replacement. Check private temporary storage, permissions, and snapshot tools.',
        'inventory_mismatch' => 'The snapshot file references do not match the authenticated object inventory.',

        'generic' => 'The operation failed. Check the application log for a sanitized reason.',
        'maintenance_active' => 'A maintenance window is already open.',
        'backup_in_progress' => 'A backup is already in progress.',
        'backup_failed' => 'The backup failed before it could be verified.',
        'import_in_flight' => 'An import is still running. Wait for it to finish before backing up.',
        'rotation_in_flight' => 'A key rotation is in progress. Wait for it to finish first.',
        'rotation_in_progress' => 'A key rotation is already in progress.',
        'rotation_failed' => 'The key rotation failed.',
        'queue_failed' => 'The worker stopped before the operation finished.',
        'nothing_to_rotate' => 'No vault key is still wrapped under an older installation KEK.',
        'vault_not_found' => 'The selected vault no longer exists.',
        'rotation_not_found' => 'The rotation run no longer exists.',
        'unknown_operation' => 'The rotation type is not recognised.',
        'references_remain' => 'Some records still reference the old key. Resume the rotation to finish them.',
        'verification_failed' => 'Rewrapped keys could not be verified. Nothing was retired.',
        'backup_required' => 'Take a verified backup before retiring an old vault key version.',
        'manifest_missing' => 'The backup manifest is missing from the set.',
        'manifest_malformed' => 'The backup manifest could not be read.',
        'backup_key_missing' => 'The backup keyring is missing or does not contain the required backup key.',
        'manifest_authentication_failed' => 'The backup manifest failed authentication; the set may have been altered.',
        'installation_mismatch' => 'The backup belongs to a different installation ID.',
        'kek_key_missing' => 'A required installation KEK is missing from the keyring.',
        'app_key_missing' => 'This backup contains framework-encrypted authentication data and needs the matching APP_KEY.',
        'passkey_secret_missing' => 'This backup needs the passkey user-handle secret.',
        'integrity_failed' => 'A checksum did not match; the backup set is corrupt.',
        'snapshot_unreadable' => 'The database snapshot could not be read.',
        'snapshot_unwritable' => 'The database snapshot could not be written.',
        'object_missing' => 'A referenced encrypted object is missing from storage.',
        'object_unwritable' => 'An encrypted object could not be copied.',
        'object_unreadable' => 'An encrypted object could not be read.',
        'restored' => 'This operation was open when the backup was taken and was reset after restore.',
        'pruned' => 'Local retention removed this set. Off-host copies are not affected.',
    ],
];
