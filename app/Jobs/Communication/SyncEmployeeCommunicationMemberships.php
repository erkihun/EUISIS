<?php

declare(strict_types=1);

namespace App\Jobs\Communication;

use App\Models\User;
use App\Services\Communication\ChannelMembershipSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One employee's assignment changed (transfer, new assignment, status): join
 * the automatic channels they now belong to and leave the ones they no
 * longer match. Unique per user so a burst of saves runs once.
 */
class SyncEmployeeCommunicationMemberships implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public function __construct(public string $employeeId) {}

    public function uniqueId(): string
    {
        return $this->employeeId;
    }

    public function handle(ChannelMembershipSync $sync): void
    {
        foreach (User::query()->where('employee_id', $this->employeeId)->get() as $user) {
            $sync->syncUser($user);
        }
    }
}
