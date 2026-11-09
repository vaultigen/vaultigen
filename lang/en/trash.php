<?php

return [

    'title' => 'Trash · :vault',

    'subheading' => 'Deleted entries and folders stay here until their purge deadline. Only a vault Manager can see and restore trash.',

    'actions' => [
        'restore' => 'Restore',
    ],

    'columns' => [
        'deleted' => 'Deleted',
        'purge_after' => 'Purge after',
    ],

    'retention_notice' => 'Trashed items are permanently purged after :days days. The scheduled purge job retries and records a sanitized failure if it cannot remove an item.',

    'restore_entry_description' => 'Restore this entry to the vault. It reattaches to its former folder when that folder still exists, otherwise it returns to the vault root ("No folder").',

    'notifications' => [
        'entry_restored' => 'Entry restored',
        'folder_restored' => 'Folder restored',
        'not_restored' => 'Not restored',
    ],

    'entries' => [
        'heading' => 'Deleted entries',
        'empty_heading' => 'No deleted entries',
        'empty_description' => 'Entries moved to the trash appear here with their purge deadline.',
    ],

    'folders' => [
        'heading' => 'Deleted folders',
        'description' => 'A folder can only be deleted while it holds no live entries. Its entries are moved or trashed first, never cascaded.',
        'empty_heading' => 'No deleted folders',
        'empty_description' => 'Folders moved to the trash appear here with their purge deadline.',
    ],

];
