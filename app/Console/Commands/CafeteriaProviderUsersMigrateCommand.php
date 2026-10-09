<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CafeteriaProviderUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Migrates legacy provider users from the admin users table to the dedicated
 * cafeteria_provider_users credential table.
 *
 * Usage:
 *   php artisan cafeteria:provider-users:migrate --dry-run   (preview only)
 *   php artisan cafeteria:provider-users:migrate              (run migration)
 */
class CafeteriaProviderUsersMigrateCommand extends Command
{
    protected $signature = 'cafeteria:provider-users:migrate {--dry-run : Preview counts without writing any data}';

    protected $description = 'Migrate legacy provider users from users table to cafeteria_provider_users';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info($dryRun ? '[DRY RUN] No data will be written.' : 'Running migration...');

        // Find users with user_type = provider and a cafeteria_provider_id set
        $legacyUsers = User::query()
            ->where('user_type', 'provider')
            ->whereNotNull('cafeteria_provider_id')
            ->get();

        if ($legacyUsers->isEmpty()) {
            $this->info('No legacy provider users found. Nothing to migrate.');

            return self::SUCCESS;
        }

        $this->info("Found {$legacyUsers->count()} legacy provider user(s).");

        $migrated = 0;
        $skipped = 0;

        foreach ($legacyUsers as $user) {
            $alreadyMigrated = CafeteriaProviderUser::query()
                ->where('cafeteria_provider_id', $user->cafeteria_provider_id)
                ->where(function ($q) use ($user): void {
                    $q->where('email', $user->email)
                        ->orWhere('username', $user->email);
                })
                ->exists();

            if ($alreadyMigrated) {
                $this->line("  SKIP  {$user->email} — already migrated.");
                $skipped++;
                continue;
            }

            $this->line("  MIGRATE  {$user->email} → provider {$user->cafeteria_provider_id}");

            if (! $dryRun) {
                CafeteriaProviderUser::create([
                    'cafeteria_provider_id' => $user->cafeteria_provider_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => null,
                    'password' => $user->getAttributes()['password'] ?? Hash::make(Str::random(32)),
                    'status' => $user->status === 'active' ? 'active' : 'inactive',
                    'portal_enabled' => true,
                    'must_change_password' => true, // force reset on first portal login
                    'metadata' => ['migrated_from_user_id' => $user->id],
                    'created_by' => null,
                    'updated_by' => null,
                ]);
            }

            $migrated++;
        }

        $this->newLine();
        $this->info("Summary: {$migrated} migrated, {$skipped} skipped.");

        if ($dryRun) {
            $this->warn('[DRY RUN] No data was written. Remove --dry-run to apply.');
        } else {
            $this->info('Migration complete. Legacy users in the users table are NOT deleted — review and clean up manually if desired.');
        }

        return self::SUCCESS;
    }
}
