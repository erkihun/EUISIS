<?php

declare(strict_types=1);

namespace Modules\Transport\Application\Services;

use App\Backbone\Events\AssignmentChanged;
use App\Backbone\Events\EmployeeChanged;
use App\Support\Features;
use Modules\Transport\Infrastructure\Jobs\ReevaluateTransportEligibility;

/**
 * Transport reacts to the canonical employee records (there is no separate
 * transport employee master): a placement change or a status change queues
 * a re-evaluation of the employee's route assignments after commit. Listens
 * to backbone events; the backbone does not call this module.
 */
class TransportEligibilitySync
{
    private const PLACEMENT = ['organization_id', 'organization_unit_id', 'assignment_status', 'is_current', 'effective_to'];

    public function onAssignmentChanged(AssignmentChanged $event): void
    {
        if (! $event->deleted && Features::enabled('transport') && ($event->created || $event->changedAny(self::PLACEMENT))) {
            ReevaluateTransportEligibility::dispatch($event->employeeId)->afterCommit();
        }
    }

    public function onEmployeeChanged(EmployeeChanged $event): void
    {
        if (Features::enabled('transport') && ! $event->created && $event->changedAny(['current_assignment_id', 'status'])) {
            ReevaluateTransportEligibility::dispatch($event->employeeId)->afterCommit();
        }
    }
}
