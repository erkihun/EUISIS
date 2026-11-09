<?php

declare(strict_types=1);

namespace App\Console\Commands\Access;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\Role;
use App\Models\TemporaryRoleGrant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Break-glass elevation (Core Stage 6, TD-17): give a named, active account a
 * role for a limited time, for a stated reason, approved by a second person.
 * The role is removed automatically by access:expire-elevations. No hidden or
 * permanent account: the grant, the approval and the removal are audited.
 *
 *   php artisan access:elevate ops@example.org "Super Admin" --minutes=60 \
 *       --reason="Restore after incident 42" --approved-by=lead@example.org
 */
class GrantTemporaryRole extends Command
{
    /** Longest elevation one command may grant: a working day. */
    public const MAX_MINUTES = 480;

    protected $signature = 'access:elevate {email : The account that receives the role} {role : The role name}
        {--minutes=60 : How long the role is held (1 to 480)}
        {--reason= : Why the elevation is needed (required, at least 10 characters)}
        {--approved-by= : E-mail of the second person who approved it (required, not the grantee)}';

    protected $description = 'Grant a role for a limited time (break-glass), with reason and approver; removed automatically when it expires';

    public function handle(WriteAuditLogAction $audit): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();
        $approver = User::query()->where('email', (string) $this->option('approved-by'))->first();
        $roleName = (string) $this->argument('role');
        $reason = trim((string) $this->option('reason'));
        $minutes = (int) $this->option('minutes');

        $problem = match (true) {
            $user === null => 'No account with that e-mail.',
            ! $user->isActive() => 'The account is not active.',
            Role::query()->where('name', $roleName)->where('guard_name', 'web')->doesntExist() => 'No role named "'.$roleName.'".',
            mb_strlen($reason) < 10 => 'A reason of at least 10 characters is required.',
            $approver === null => 'The approver (--approved-by) must be an existing account.',
            $approver->is($user) => 'The approver must be a second person, not the grantee.',
            $minutes < 1 || $minutes > self::MAX_MINUTES => 'Minutes must be between 1 and '.self::MAX_MINUTES.'.',
            $user->hasRole($roleName) => 'The account already holds this role; nothing to grant.',
            default => null,
        };
        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $grant = DB::transaction(function () use ($user, $approver, $roleName, $reason, $minutes, $audit): TemporaryRoleGrant {
            $user->assignRole($roleName);
            $grant = TemporaryRoleGrant::query()->create([
                'user_id' => $user->getKey(),
                'role_name' => $roleName,
                'reason' => $reason,
                'approved_by' => $approver->getKey(),
                'granted_at' => now(),
                'expires_at' => now()->addMinutes($minutes),
            ]);
            $audit->execute(
                AuditEventType::TemporaryRoleGranted,
                null,
                $user,
                newValues: ['role' => $roleName, 'expires_at' => $grant->expires_at->toIso8601String(), 'approved_by' => $approver->getKey(), 'grant_id' => $grant->getKey()],
                reason: $reason,
            );

            return $grant;
        });

        $this->info('Granted "'.$roleName.'" to '.$user->email.' until '.$grant->expires_at->toDateTimeString().' (grant '.$grant->getKey().').');

        return self::SUCCESS;
    }
}
