<?php

declare(strict_types=1);

namespace Modules\Grievance\Infrastructure\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Grievance\Application\Services\GrievanceEscalationService;

/**
 * Queueable entry point for the grievance SLA sweep (the scheduler runs the
 * grievances:process-sla command directly). All the logic — configured
 * routes, locking, idempotency — lives in GrievanceEscalationService; the
 * first version's hard-coded "second breach goes to the tribunal" is gone.
 */
class EscalateOverdueGrievancesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('long');
    }

    public function uniqueId(): string
    {
        return 'grievance-sla-sweep';
    }

    public function handle(GrievanceEscalationService $escalation): void
    {
        $escalation->processDueStages();
    }
}
