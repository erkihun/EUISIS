<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Audit\WriteAuditLogAction;
use App\Actions\Users\CreateUserAction;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Support\Demo\DemoDataset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The accounts that perform the seeder's actions, so every audit entry names
 * a demo person: the DEMO City Admin (maker) and the second people that
 * maker-checker steps require. Organization scopes are added later by
 * DemoUserSeeder, once the organizations exist.
 */
class DemoActorSeeder extends DemoSeeder
{
    public function run(CreateUserAction $createUser, WriteAuditLogAction $audit): void
    {
        $maker = $this->bootstrapMaker($audit);

        foreach ([DemoDataset::CHECKER_EMAIL, DemoDataset::CARD_APPROVER_EMAIL] as $email) {
            self::ensureUser($email, $maker, $createUser);
        }
    }

    /**
     * Nobody exists yet to create the first account, so it is created
     * directly — as UserSeeder does — and its creation is audited.
     */
    private function bootstrapMaker(WriteAuditLogAction $audit): User
    {
        $existing = DemoDataset::user(DemoDataset::MAKER_EMAIL);
        if ($existing !== null) {
            return $existing;
        }

        $definition = DemoDataset::users()[DemoDataset::MAKER_EMAIL];

        return DB::transaction(function () use ($definition, $audit): User {
            $maker = User::query()->create([
                'name' => $definition['name'],
                'email' => DemoDataset::MAKER_EMAIL,
                'password' => Hash::make(self::demoPassword()),
                'status' => 'active',
                'must_change_password' => false,
            ]);
            $maker->forceFill(['email_verified_at' => now()])->save();
            $maker->assignRole($definition['roles']);

            $audit->execute(AuditEventType::UserCreated, null, $maker, null,
                newValues: ['name' => $maker->name, 'email' => $maker->email, 'roles' => $definition['roles'], 'source' => 'demo-seeder']);

            return $maker;
        });
    }

    /** Creates one catalog account through CreateUserAction; an existing account is left as it is. */
    public static function ensureUser(string $email, User $actor, CreateUserAction $createUser): User
    {
        $existing = DemoDataset::user($email);
        if ($existing !== null) {
            return $existing;
        }

        $definition = DemoDataset::users()[$email];
        $user = $createUser->execute([
            'name' => $definition['name'],
            'email' => $email,
            'password' => self::demoPassword(),
            'status' => 'active',
            'must_change_password' => false,
            'roles' => $definition['roles'],
        ], $actor);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
