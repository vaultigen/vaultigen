<?php

return [

    'columns' => [
        'members' => 'Members',
        'created' => 'Created',
        'platform_roles' => 'Platform roles',
        'added' => 'Added',
        'active' => 'Active',
        'verified' => 'Verified',
    ],

    'actions' => [
        'manage' => 'Manage team',
        'create' => 'Create team',
        'back' => 'Teams',
        'add_member' => 'Add member',
        'add_member_heading' => 'Add a team member',
        'add_member_description' => 'The user joins the team immediately. They gain only the access already granted to this team.',
        'add_member_submit' => 'Add member',
        'remove' => 'Remove',
        'remove_tooltip' => 'Remove from team',
        'remove_heading' => 'Remove team member',
        'remove_description' => 'They lose the access this team carries immediately. Access granted directly to the user is unaffected.',
        'remove_selected' => 'Remove selected',
        'remove_selected_description' => 'Every selected member loses the access this team carries immediately.',
    ],

    'fields' => [
        'user' => 'User',
        'ordinary_user' => 'Ordinary user',
    ],

    'filters' => [
        'platform_role' => 'Platform role',
        'active' => 'Active',
        'platform_role_options' => [
            'super_admin' => 'Super Admin',
            'auditor' => 'Auditor',
            'vault_creator' => 'Vault creator',
        ],
    ],

    'empty' => [
        'teams_heading' => 'No teams yet',
        'teams_description' => 'Create a team to organize membership.',
        'members_heading' => 'No members yet',
        'members_description' => 'Add a user to make this team useful. Access is only granted where the team itself is granted access.',
    ],

    'notifications' => [
        'member_added' => 'Member added',
        'member_removed' => 'Member removed',
        'members_removed' => 'Members removed',
        'renamed' => 'Team renamed',
        'not_renamed' => 'Team not renamed',
    ],

    'manage' => [
        'title' => 'Team · :name',
        'subheading' => 'Rename this team and manage who belongs to it. Team membership grants access by team wherever the team is granted access.',
        'team_name' => 'Team name',
        'team_name_description' => 'The name is stored in the clear and must be unique across the installation.',
        'save_name' => 'Save name',
        'members' => 'Members',
        'members_description' => 'Search, filter, and choose which columns to show. Add members from the header or remove them from each row.',
    ],

];
