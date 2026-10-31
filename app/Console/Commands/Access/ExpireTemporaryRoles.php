<?php

declare(strict_types=1);

namespace App\Console\Commands\Access;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\TemporaryRoleGrant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes break-glass roles whose time is up (scheduled every minute). A role
 * stays only while another open, unexpired grant of the same role covers the
 * account; a role the account held before the elevation is never granted
 * temporarily, so it is never removed here.
 */
class ExpireTemporaryRoles extends Command
{
    protected $signature = 'access:expire-elevations';

    protected $description = 'Remove time-limited (break-glass) roles that have expired, and audit each removal';

    public function handle(WriteAuditLogAction $audit): int
    {
        $ended = 0;

        TemporaryRoleGrant::query()
            ->open()
            ->where('expires_at', '<=', now())
            ->with('user')
            ->chunkById(100, function ($grants) use ($audit, &$ended): void {
                foreach ($grants as $grant) {
                    DB::transaction(function () use ($grant, $audit): void {
                        $grant->forceFill(['ended_at' => now(), 'end_reason' => 'expired'])->save();

                        $stillCovered = TemporaryRoleGrant::query()->open()
                            ->where('user_id', $grant->user_id)
                            ->where('role_name', $grant->role_name)
                            ->where('expires_at', '>', now())
                            ->exists();
                        if ($grant->user !== null && ! $stillCovered && $grant->user->hasRole($grant->role_name)) {
                            $grant->user->removeRole($grant->role_name);
                        }

                        $audit->execute(
                            AuditEventType::TemporaryRoleEnded,
                            null,
                            $grant->user,
                            oldValues: ['role' => $grant->role_name],
                            newValues: ['grant_id' => $grant->getKey(), 'ended' => 'expired', 'role_removed' => ! $stillCovered],
                            reason: 'Break-glass elevation expired',
                        );
                    });
                    $ended++;
                }
            });

        $this->info("Ended {$ended} expired elevation(s).");

        return self::SUCCESS;
    }
}
