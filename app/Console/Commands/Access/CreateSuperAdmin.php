<?php

declare(strict_types=1);

namespace App\Console\Commands\Access;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Role;
use App\Models\User;
use App\Security\Passwords\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Creates a named Super Admin from the server console, for the first
 * administrator of a new installation or when no Super Admin can sign in.
 * Later administrators are created on the Users page.
 *
 * Arguments take no quotes (hosting panels split on spaces): the name may be
 * several words. The password is a generated one-time password, printed once
 * and never logged; it must be changed at first sign-in, and MFA enrolment
 * follows for this role. Refuses an existing e-mail address: it never changes
 * an existing account.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'user:create-super-admin {email : E-mail address of the new account} {name* : Full name (several words allowed, no quotes needed)}';

    protected $description = 'Create a named Super Admin with a one-time password (refuses an existing e-mail)';

    public function handle(PasswordPolicy $passwords, WriteAuditLogAction $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = trim(implode(' ', (array) $this->argument('name')));

        $validator = Validator::make(['email' => $email, 'name' => $name], [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            $this->error(implode(' ', $validator->errors()->all()));

            return self::FAILURE;
        }

        if (! Role::query()->where('name', 'Super Admin')->where('guard_name', 'web')->exists()) {
            $this->error('The Super Admin role does not exist. Run: php artisan db:seed --class=PermissionSeeder --force, then RoleSeeder and RolePermissionSeeder.');

            return self::FAILURE;
        }

        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            $this->error("An account with {$email} already exists. Nothing was changed.");

            return self::FAILURE;
        }

        $password = $passwords->generateTemporaryPassword();

        $user = DB::transaction(function () use ($email, $name, $password, $audit): User {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'status' => 'active',
                'must_change_password' => true,
                'password_changed_at' => null,
            ]);
            $user->assignRole('Super Admin');

            $audit->execute(AuditEventType::UserCreated, null, $user, null,
                newValues: ['name' => $user->name, 'email' => $user->email, 'roles' => ['Super Admin']],
                reason: 'Created from the server console (user:create-super-admin).');

            return $user;
        });

        $this->info("Super Admin created: user {$user->id}, {$email}.");
        $this->line("One-time password: {$password}");
        $this->line('Sign in, set a new password, then set up MFA with an authenticator app. The one-time password is not shown again.');

        return self::SUCCESS;
    }
}
