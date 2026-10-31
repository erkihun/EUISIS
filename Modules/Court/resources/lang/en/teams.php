<?php

return [
    'title' => 'Teams',

    'page_header' => [
        'index' => 'Team Management',
        'create' => 'Create team',
        'edit' => 'Edit team',
        'show' => 'Team detail',
    ],

    'headings' => [
        'current' => 'Current teams',
        'details' => 'Team details',
        'members' => 'Members',
        'overview' => 'Team overview',
    ],

    'descriptions' => [
        'current' => 'Only one team per user is allowed, and assignments happen inside the team detail views.',
        'details' => 'Name, description and hierarchy are required for organizing your court teams.',
        'edit_notice' => 'Changes here are reflected in case assignment dropdowns and reports.',
        'members_edit_notice' => 'Assign users to this team (each user may belong to only one team).',
        'members_leader_notice' => 'Team leader must always remain a member; unchecking them here will have no effect.',
        'leader_optional' => 'Optional. Choose a primary contact for this team.',
        'description_optional' => 'No description supplied.',
        'description_missing' => 'No description provided for this team.',
        'members_empty' => 'No members assigned yet.',
    ],

    'labels' => [
        'team' => 'Team',
        'members' => 'Members',
        'leader' => 'Leader',
        'description' => 'Description',
        'actions' => 'Actions',
        'name' => 'Name',
        'parent' => 'Parent team',
        'team_leader' => 'Team leader',
        'none' => 'None',
        'reports_to' => 'Reports to :team',
        'top_level' => 'Top-level team',
        'member_count' => 'members',
        'assigned' => 'assigned',
        'leader_prefix' => 'Leader:',
    ],

    'buttons' => [
        'add_new' => 'Add new team',
        'view' => 'View',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'manage_members' => 'Manage members',
        'create' => 'Create team',
        'save_changes' => 'Save changes',
        'save_members' => 'Save members',
        'back_to_list' => 'Back to list',
    ],

    'confirmations' => [
        'delete' => 'Delete this team?',
    ],

    'empty' => 'No teams defined yet.',

    'meta' => [
        'unassigned' => 'Unassigned',
        'unknown_email' => '',
    ],

    'errors' => [
        'parent_cycle' => 'A team cannot report to itself or to one of its sub-teams.',
        'fix' => 'Please fix the following:',
    ],
];
