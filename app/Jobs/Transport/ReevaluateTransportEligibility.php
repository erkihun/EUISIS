<?php

declare(strict_types=1);

namespace App\Jobs\Transport;

use App\Models\Employee;
use App\Services\Transport\TransportEligibilityReevaluator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Queued after a placement or status change commits (TransportEligibilityObserver). */
class ReevaluateTransportEligibility implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $employeeId) {}

    public function handle(TransportEligibilityReevaluator $reevaluator): void
    {
        $employee = Employee::query()->with('currentAssignment.organization')->find($this->employeeId);
        if ($employee !== null) {
            $reevaluator->reevaluate($employee);
        }
    }
}
