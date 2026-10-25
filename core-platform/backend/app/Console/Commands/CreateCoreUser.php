<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates a Core account with a one-time password printed once. The first
 * account on a new Core installation is made this way (role core_admin).
 */
class CreateCoreUser extends Command
{
    protected $signature = 'core:create-user {email} {name*} {--role=core_viewer : core_viewer, core_officer or core_admin} {--organization= : Code of the organization the account is scoped to (empty = whole city)}';

    protected $description = 'Create a Core account with a one-time password';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $role = (string) $this->option('role');
        if (! in_array($role, User::ROLES, true)) {
            $this->error('Role must be one of: '.implode(', ', User::ROLES).'.');

            return self::FAILURE;
        }
        if (User::query()->where('email', $email)->exists()) {
            $this->error('An account with this e-mail already exists.');

            return self::FAILURE;
        }

        $scope = null;
        if ($this->option('organization') !== null) {
            $scope = Organization::query()->where('code', (string) $this->option('organization'))->value('id');
            if ($scope === null) {
                $this->error('No organization with that code.');

                return self::FAILURE;
            }
        }

        $password = Str::password(16);
        User::query()->create([
            'name' => implode(' ', (array) $this->argument('name')),
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'scope_organization_id' => $scope,
            'is_active' => true,
        ]);

        $this->info("Created {$role} {$email}. One-time password (shown once): {$password}");

        return self::SUCCESS;
    }
}
