<?php

declare(strict_types=1);

namespace Modules\FieldWork\Application\Services;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\SupervisorDelegation;
use App\Models\User;
use App\Services\ReportingLines\AuthorityDecision;
use App\Services\ReportingLines\ReportingAuthority;
use App\Services\ReportingLines\SupervisorActions;
use Illuminate\Support\Carbon;
use Modules\DailyWork\Contracts\DailyWorkReviewers;
use Modules\DailyWork\Contracts\Data\ManagerResolution;
use Modules\FieldWork\Domain\Models\FieldWorkRequest;

/**
 * Who is the immediate supervisor that decides a field work request.
 *
 * The answer is the line manager the Daily Work contract names for the
 * requester's snapshot placement (employee → nearest unit up the tree →
 * organization). With structural reporting switched on (ADR 0025) that
 * contract asks the organization structure first — the unit's head, a
 * published position profile, an acting appointment — and falls back to the
 * explicit reviewer assignments. An ambiguous tie or no answer resolves to
 * nobody: SUPERVISOR_NOT_RESOLVED, never "any user with a manager role",
 * never auto-approval.
 *
 * Authority is checked LIVE at decision time against the snapshot placement,
 * so a supervisor replaced since submission can no longer decide, and their
 * successor can. A colleague holding a delegation of field_work.decide from
 * that supervisor may decide too, and the decision records whose authority
 * was used. Nobody decides a request they take part in.
 */
class FieldWorkSupervisorResolver
{
    public function __construct(
        private readonly DailyWorkReviewers $managers,
        private readonly FieldWorkSettings $settings,
        private readonly ReportingAuthority $authority,
    ) {}

    public function resolve(Employee $requester, ?EmployeeAssignment $assignment): ?User
    {
        return $this->resolution($requester, $assignment)->user;
    }

    public function resolution(Employee $requester, ?EmployeeAssignment $assignment): ManagerResolution
    {
        $resolution = $this->managers->directManagerResolution($requester, $assignment, $this->onDate());
        $manager = $resolution->user;
        if ($manager === null || $manager->status !== 'active') {
            return ManagerResolution::nobody($resolution->structuralOutcome);
        }

        return $resolution;
    }

    public function resolveFor(FieldWorkRequest $request): ?User
    {
        return $this->resolutionFor($request)->user;
    }

    public function resolutionFor(FieldWorkRequest $request): ManagerResolution
    {
        $request->loadMissing(['requester', 'assignment']);

        return $this->resolution($request->requester, $this->placementOf($request));
    }

    public function isSupervisorOf(User $user, FieldWorkRequest $request): bool
    {
        return $this->authorityOf($user, $request)->granted;
    }

    /**
     * Direct: the user is the supervisor resolved live. Delegated: someone
     * lent them field_work.decide and is themself the live direct supervisor
     * (ReportingAuthority re-checks that on the day). Participants never.
     */
    public function authorityOf(User $user, FieldWorkRequest $request): AuthorityDecision
    {
        $employeeId = $user->employee?->id;
        if ($employeeId !== null && $request->participants()->where('employee_id', $employeeId)->exists()) {
            return AuthorityDecision::denied('reporting-lines.authority.self');
        }

        if ($this->resolveFor($request)?->is($user) ?? false) {
            return AuthorityDecision::direct();
        }

        if (! config('features.structural_reporting') || $employeeId === null) {
            return AuthorityDecision::denied('reporting-lines.authority.none');
        }
        $request->loadMissing('requester');
        $decision = $this->authority->decide($user, $request->requester, SupervisorActions::FIELD_WORK_DECIDE, $this->placementOf($request), $this->onDate());

        return $decision->basis === AuthorityDecision::DELEGATED ? $decision : AuthorityDecision::denied($decision->reason);
    }

    /**
     * Users whose field-work decisions this user may make in their place
     * today: the accounts of employees who delegated field_work.decide to
     * the user's employee. Empty unless structural reporting is on.
     *
     * @return list<int>
     */
    public function delegatorUserIds(User $user): array
    {
        $employeeId = $user->employee_id;
        if ($employeeId === null || ! config('features.structural_reporting')) {
            return [];
        }
        $delegators = SupervisorDelegation::query()
            ->where('delegate_employee_id', $employeeId)
            ->inForceOn($this->onDate())
            ->get(['delegator_employee_id', 'actions'])
            ->filter(fn (SupervisorDelegation $d): bool => $d->allows(SupervisorActions::FIELD_WORK_DECIDE))
            ->pluck('delegator_employee_id');
        if ($delegators->isEmpty()) {
            return [];
        }

        return User::query()->whereIn('employee_id', $delegators)->where('status', 'active')->pluck('id')->map(fn ($id): int => (int) $id)->all();
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
