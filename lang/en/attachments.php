<?php

return [

    'heading' => 'Attachments',
    'description' => 'Files are encrypted with a per-file key before they are stored. Filenames and detected types are encrypted too, and every download is audited.',

    'empty' => [
        'heading' => 'No attachments yet',
        'description' => 'Upload an encrypted file to keep it with this entry.',
    ],

    'columns' => [
        'filename' => 'File',
        'size' => 'Size',
        'uploaded_by' => 'Uploaded by',
        'uploaded_at' => 'Uploaded',
    ],

    'quota' => [
        'label' => 'Vault storage',
        'usage' => ':used of :limit used',
        'per_file' => 'Up to :size per file.',
    ],

    'upload' => [
        'label' => 'Add a file',
        'help' => 'Up to :size. The file is encrypted before it is written to storage and never rendered inline.',
        'submit' => 'Upload and encrypt',
        'busy' => 'Encrypting and uploading…',
    ],

    'actions' => [
        'download' => 'Download',
        'remove' => 'Remove',
    ],

    'remove_confirm' => 'Remove this attachment? It moves to the vault trash and is purged after the retention period.',

    'notifications' => [
        'uploaded' => 'Attachment uploaded',
        'deleted' => 'Attachment removed',
    ],

    'errors' => [
        'heading' => 'The file could not be added.',
    ],

    'validation' => [
        'too_large' => 'The file is larger than the 25 MB per-file limit.',
        'vault_quota' => 'This vault has reached its 500 MB storage limit.',
        'missing' => 'Choose a file to upload.',
        'upload_failed' => 'The upload did not complete. Choose the file again.',
        'empty' => 'The file is empty.',
        'unreadable' => 'The uploaded file could not be read.',
    ],

    'sizes' => [
        'bytes' => ':count B',
        'kilobytes' => ':count KB',
        'megabytes' => ':count MB',
    ],

];
