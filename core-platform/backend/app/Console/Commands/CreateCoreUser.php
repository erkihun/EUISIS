<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CoreRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates a Core account with a one-time password printed once. The first
 * account on a new Core installation is made this way (role core_admin with
 * --citywide). Scope is explicit (ADR 0008): --organization (with everything
 * under it, or --self), or --citywide; with neither the account reaches no
 * organizational data.
 */
class CreateCoreUser extends Command
{
    protected $signature = 'core:create-user {email} {name*} {--role=core_viewer : core_viewer, core_officer or core_admin} {--organization= : Code of the organization the account is scoped to} {--self : Only that organization, not the ones under it} {--citywide : The whole city (explicit; never the default)}';

    protected $description = 'Create a Core account with a one-time password';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $role = (string) $this->option('role');
        if (! CoreRole::query()->where('key', $role)->exists()) {
            $this->error('Role must be one of: '.CoreRole::query()->orderBy('id')->pluck('key')->implode(', ').'.');

            return self::FAILURE;
        }
        if (User::query()->where('email', $email)->exists()) {
            $this->error('An account with this e-mail already exists.');

            return self::FAILURE;
        }

        if ($this->option('citywide') && $this->option('organization') !== null) {
            $this->error('Choose --citywide or --organization, not both.');

            return self::FAILURE;
        }
        $scope = null;
        $scopeType = $this->option('citywide') ? User::SCOPE_CITYWIDE : null;
        if ($this->option('organization') !== null) {
            $scopeType = $this->option('self') ? User::SCOPE_SELF : User::SCOPE_SUBTREE;
            $scope = Organization::query()->where('code', (string) $this->option('organization'))->value('id');
            if ($scope === null) {
                $this->error('No organization with that code.');

                return self::FAILURE;
            }
        }

        $password = Str::password(16);
        $user = new User([
            'name' => implode(' ', (array) $this->argument('name')),
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'scope_organization_id' => $scope,
            'scope_type' => $scopeType,
            'is_active' => true,
        ]);
        $user->must_change_password = true;
        $user->save();
        app(AuditWriter::class)->writeFor('user.created', null, 'User', (string) $user->id, null, null, $user->only(['name', 'email', 'role', 'scope_type', 'scope_organization_id', 'is_active']));

        if ($scopeType === null) {
            $this->warn('No scope given: the account reaches no organizational data until an administrator gives it one.');
        }
        $this->info("Created {$role} {$email}. One-time password (shown once): {$password}");

        return self::SUCCESS;
    }
}
