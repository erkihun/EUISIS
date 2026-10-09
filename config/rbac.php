<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default role synchronization (docs/rbac-architecture.md)
    |--------------------------------------------------------------------------
    | How RolePermissionSeeder applies App\Support\Rbac\DefaultRoleMatrix to
    | the system-managed roles:
    |
    |   sync      the role ends up with exactly the matrix permissions
    |             (missing ones added, extra ones removed)
    |   additive  missing matrix permissions are added; nothing is removed
    |
    | Custom roles (not in the matrix) are never touched in either mode.
    */
    'default_role_sync' => env('RBAC_DEFAULT_ROLE_SYNC', 'sync'),
];
