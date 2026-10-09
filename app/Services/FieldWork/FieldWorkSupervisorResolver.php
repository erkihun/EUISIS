<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\FieldWorkRequest;
use App\Models\User;
use App\Services\Assessment\Execution\AssessmentEvaluatorResolver;
use Illuminate\Support\Carbon;

/**
 * Who is the immediate supervisor that decides a field work request.
 *
 * EUISIS records no supervisor on positions or units, so the answer comes
 * from the explicit line-manager (reviewer) assignments that already define
 * that authority for Daily Activity, EPMS and assessments — the same
 * resolution as AssessmentEvaluatorResolver::directManager(): the most
 * specific active assignment covering the requester's snapshot placement
 * (employee → nearest unit up the tree → organization). An ambiguous tie or
 * no assignment resolves to nobody: SUPERVISOR_NOT_RESOLVED, never "any user
 * with a manager role", never auto-approval.
 *
 * Authority is checked LIVE at decision time against the snapshot placement,
 * so a supervisor replaced since submission can no longer decide, and their
 * successor can. Nobody decides a request they take part in.
 *
 * NEEDS_DECISION: acting/delegated supervisors. No general delegation model
 * exists yet (GrievanceDelegation is grievance-specific).
 */
class FieldWorkSupervisorResolver
{
    public function __construct(
        private readonly AssessmentEvaluatorResolver $managers,
        private readonly FieldWorkSettings $settings,
    ) {}

    public function resolve(Employee $requester, ?EmployeeAssignment $assignment): ?User
    {
        $manager = $this->managers->directManager($requester, $assignment, $this->onDate());

        return $manager !== null && $manager->status === 'active' ? $manager : null;
    }

    public function resolveFor(FieldWorkRequest $request): ?User
    {
        $request->loadMissing(['requester', 'assignment']);

        return $this->resolve($request->requester, $this->placementOf($request));
    }

    public function isSupervisorOf(User $user, FieldWorkRequest $request): bool
    {
        $employeeId = $user->employee?->id;
        if ($employeeId !== null && $request->participants()->where('employee_id', $employeeId)->exists()) {
            return false;
        }

        return $this->resolveFor($request)?->is($user) ?? false;
    }

    /**
     * The placement the request was made from. The snapshot columns win over
     * the live assignment row, so a later transfer that edits or closes the
     * assignment cannot move the request to another supervisor's team.
     */
    private function placementOf(FieldWorkRequest $request): EmployeeAssignment
    {
        $placement = new EmployeeAssignment;
        $placement->forceFill([
            'employee_id' => $request->requester_employee_id,
            'organization_id' => $request->organization_id,
            'organization_unit_id' => $request->organization_unit_id,
            'position_id' => $request->position_id,
        ]);

        return $placement;
    }

    private function onDate(): Carbon
    {
        return $this->settings->today();
    }
}
