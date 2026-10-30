<?php

declare(strict_types=1);

namespace Modules\Transport\Infrastructure\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Transport\Application\Services\TransportEligibilityReevaluator;

/** Queued after a placement or status change commits (TransportEligibilitySync, on backbone events). */
class ReevaluateTransportEligibility implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $employeeId) {}

    public function handle(TransportEligibilityReevaluator $reevaluator): void
    {
        $reevaluator->reevaluateById($this->employeeId);
    }
}
