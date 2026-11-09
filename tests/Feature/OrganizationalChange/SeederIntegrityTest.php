<?php

declare(strict_types=1);

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

/**
 * The seeded roles must keep the separation of duties intact: a requester
 * profile that can ask for changes but cannot edit master data.
 */
test('seeded roles keep request and edit permissions separate', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $requester = Role::findByName('Structure Change Requester', 'web');
    $reviewer = Role::findByName('Structure Change Reviewer', 'web');
    $implementer = Role::findByName('Structure Implementation Officer', 'web');

    $requesterPerms = $requester->permissions->pluck('name');

    // Can ask.
    expect($requesterPerms)->toContain('organization-units.request_create')
        ->and($requesterPerms)->toContain('positions.request_create')
        ->and($requesterPerms)->toContain('organizational-change-requests.submit');

    // Cannot edit master data, and cannot approve or implement.
    foreach ([
        'organization-units.create', 'organization-units.update', 'organization-units.manageHierarchy',
        'positions.create', 'positions.update', 'positions.move',
        'organizational-change-requests.approve', 'organizational-change-requests.implement',
    ] as $forbidden) {
        expect($requesterPerms)->not->toContain($forbidden);
    }

    // A reviewer approves but never implements.
    $reviewerPerms = $reviewer->permissions->pluck('name');
    expect($reviewerPerms)->toContain('organizational-change-requests.approve')
        ->and($reviewerPerms)->not->toContain('organizational-change-requests.implement')
        ->and($reviewerPerms)->not->toContain('organization-units.create');

    // An implementer applies but never approves.
    $implementerPerms = $implementer->permissions->pluck('name');
    expect($implementerPerms)->toContain('organizational-change-requests.implement')
        ->and($implementerPerms)->toContain('organization-units.create')
        ->and($implementerPerms)->not->toContain('organizational-change-requests.approve');
});
