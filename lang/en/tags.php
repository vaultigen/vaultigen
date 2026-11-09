<?php

return [

    'title' => 'Tags',
    'back' => 'Entries',

    'columns' => [
        'entries' => 'Entries',
        'vaults' => 'Vaults',
        'updated' => 'Updated',
    ],

    'filters' => [
        'assigned' => 'Assigned to entries',
        'not_assigned' => 'Not assigned',
        'any' => 'Any',
        'used_in_vault' => 'Used in vault',
        'scope' => 'Scope',
        'assigned_in_vault' => 'Assigned in this vault',
        'not_assigned_in_vault' => 'Not assigned in this vault',
    ],

    'scope' => [
        'global' => 'Global',
        'vault' => 'This vault',
    ],

    'groups' => [
        'scope' => 'Scope',
        'global' => 'Global tags',
        'vault' => 'This vault’s tags',
    ],

    'placeholder' => [
        'no_color' => 'No color',
    ],

    'options' => [
        'global_suffix' => ':name (global)',
    ],

    'actions' => [
        'new_global_tag' => 'New global tag',
        'create_global_heading' => 'Create a global tag',
        'new_tag' => 'New tag',
        'create_heading' => 'Create a tag',
        'create_description' => 'This tag will only be available in this vault.',
        'rename' => 'Rename',
        'rename_global_heading' => 'Rename global tag',
        'edit' => 'Edit',
        'edit_heading' => 'Edit tag',
        'delete' => 'Delete',
        'delete_global_heading' => 'Delete global tag',
        'delete_heading' => 'Delete tag',
        'delete_submit' => 'Delete tag',
    ],

    'empty' => [
        'global_heading' => 'No global tags yet',
        'global_description' => 'Create a tag here to make it available in every vault.',
        'heading' => 'No tags yet',
        'description' => 'Add a tag to label entries across folders.',
    ],

    'warning' => [
        'not_attached' => 'This tag is not attached to any entry. It can be deleted safely.',
        'attached_entries' => '{1} This tag is attached to :count entry|[2,*] This tag is attached to :count entries',
        'across_vaults' => '{1} across :count vault.|[2,*] across :count vaults.',
        'in_vaults' => 'In: :vaults.',
        'removes_label' => 'Deleting it removes the label from those entries.',
        'attached_entries_vault' => '{1} This tag is attached to :count entry in this vault.|[2,*] This tag is attached to :count entries in this vault.',
    ],

    'notifications' => [
        'global_created' => 'Global tag created',
        'global_not_created' => 'Global tag not created',
        'global_updated' => 'Global tag updated',
        'global_not_updated' => 'Global tag not updated',
        'global_deleted' => 'Global tag deleted',
        'created' => 'Tag created',
        'not_created' => 'Tag not created',
        'updated' => 'Tag updated',
        'not_updated' => 'Tag not updated',
        'deleted' => 'Tag deleted',
        'not_deleted' => 'Tag not deleted',
    ],

    'subheading' => 'Global tags are available to every vault. Vault-only tags are managed from inside a vault.',
    'manage_subheading' => 'Global tags are available everywhere; tags you add here belong to this vault only. An entry can carry several.',

    'errors' => [
        'no_access_to_add' => 'You do not have access to add tags in this vault.',
        'choose_valid' => 'Choose tags that are global or belong to this vault.',
        'enter_name' => 'Enter a tag name.',
        'global_manager_only' => 'Only a Super Admin can manage installation-wide tags.',
        'editor_required' => 'You need Editor access to manage this vault’s tags.',
        'vault_not_active' => 'This vault is not active, so its tags cannot be changed.',
        'duplicate_vault' => 'A tag with that name already exists in this vault.',
        'duplicate_global' => 'A global tag with that name already exists.',
        'duplicate_global_shadow' => 'A global tag with that name already exists. Use the global tag instead.',
    ],

];
