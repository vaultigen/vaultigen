<?php

return [

    'title' => 'Folders',

    'subheading' => 'Only a vault Manager can create folders. Use Edit to rename a folder or manage who can reach the entries inside it.',

    'actions' => [
        'entries' => 'Entries',
        'new_folder' => 'New folder',
        'new_entry' => 'New entry',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'trash' => 'Trash',
    ],

    'columns' => [
        'entries' => 'Entries',
        'updated' => 'Updated',
    ],

    'create_modal' => [
        'heading' => 'Create a folder',
        'description' => 'Folders are a single level. Nested folders are not supported.',
    ],

    'empty' => [
        'heading' => 'No folders yet',
        'description' => 'Create a folder to group entries. Entries without a folder stay at the vault root and are visible to everyone with vault access.',
    ],

    'delete_modal' => [
        'description' => 'Move this folder to the trash? Only an empty folder can be deleted; move or delete its entries first. Folders are never deleted with their contents.',
    ],

    'notifications' => [
        'created' => 'Folder created',
        'not_created' => 'Folder not created',
        'deleted' => 'Folder moved to trash',
        'not_deleted' => 'Folder not deleted',
    ],

    'errors' => [
        'manager_only' => 'Only a vault Manager can manage folders.',
        'vault_inactive' => 'This vault is not active, so its folders cannot be changed.',
        'name_required' => 'Enter a folder name.',
        'stale' => 'This folder changed since you opened it. Reload and try again.',
        'duplicate' => 'A folder with that name already exists in this vault.',
        'not_empty' => 'Move or delete the entries in this folder before deleting the folder. Folders are never deleted with their contents.',
    ],

];
