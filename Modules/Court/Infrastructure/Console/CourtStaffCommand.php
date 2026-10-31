<?php

declare(strict_types=1);

namespace Modules\Court\Infrastructure\Console;

use App\Models\User;
use Database\Seeders\Court\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Modules\Court\Domain\Models\CourtStaffMember;
use Modules\Court\Domain\Models\Role;

/**
 * Lets an EUISIS account work in the court (ADR 0020): its court staff record
 * and a court role. The court's permissions and default roles are seeded first
 * when they are missing, so this is also how the first court administrator is made.
 */
final class CourtStaffCommand extends Command
{
    protected $signature = 'court:staff {email : The EUISIS account\'s email} {--role=admin : The court role to give (admin, staff, viewer or one made on the Roles page)}';

    protected $description = 'Add an EUISIS account to the court staff with a court role';

    public function handle(): int
    {
        $account = User::query()->where('email', (string) $this->argument('email'))->first();
        if ($account === null) {
            $this->error('No EUISIS account has that email.');

            return self::FAILURE;
        }

        if (Role::query()->doesntExist()) {
            (new RolesAndPermissionsSeeder)->run();
            $this->info('Seeded the court\'s permissions and default roles.');
        }

        $role = Role::query()->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $this->option('role'))])->first();
        if ($role === null) {
            $this->error('No court role is named '.$this->option('role').'.');

            return self::FAILURE;
        }

        $member = CourtStaffMember::query()->find($account->getKey()) ?? (new CourtStaffMember)->forceFill(['id' => $account->getKey()]);
        $member->fill(['name' => $account->name, 'email' => $account->email, 'status' => 'active'])->save();
        $member->roles()->syncWithoutDetaching([$role->id]);

        $this->info("{$account->email} works in the court as {$role->name}. The court is under Tribunal & Court in the menu (/court/admin/dashboard).");

        return self::SUCCESS;
    }
}
