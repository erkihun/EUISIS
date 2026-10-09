<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Enums\EmployeeAvailabilityStatus;
use App\Enums\FieldWorkSessionStatus;
use App\Enums\FieldWorkStatus;
use App\Models\Employee;
use App\Models\FieldWorkParticipant;
use App\Models\FieldWorkSession;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Scoped operational availability, deliberately not a live-location or attendance service. */
final class EmployeeAvailabilityService
{
    public function __construct(private readonly OrganizationScopeService $scope) {}

    /** @return array{status:EmployeeAvailabilityStatus,session:?FieldWorkSession,reason:?string} */
    public function getEmployeeStatusAt(Employee $employee, Carbon $at): array
    {
        $session = FieldWorkSession::query()
            ->with('request:id,reference_number,destination_location,expected_return_at')
            ->where('employee_id', $employee->id)
            ->where('approved_start_at', '<=', $at)
            ->where('approved_end_at', '>=', $at)
            ->latest('approved_start_at')
            ->first();

        if ($session?->status === FieldWorkSessionStatus::CheckedIn) {
            return ['status' => EmployeeAvailabilityStatus::OfficialFieldWork, 'session' => $session, 'reason' => null];
        }
        if ($session?->status === FieldWorkSessionStatus::ApprovedNotCheckedIn) {
            return ['status' => EmployeeAvailabilityStatus::ApprovedNotCheckedIn, 'session' => $session, 'reason' => 'Approved field work has no recorded check-in.'];
        }

        $approvedWithoutSession = FieldWorkParticipant::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->whereHas('request', fn ($requests) => $requests
                ->where('status', FieldWorkStatus::Approved->value)
                ->where('starts_at', '<=', $at)
                ->where('expected_return_at', '>=', $at))
            ->exists();
        if ($approvedWithoutSession) {
            return ['status' => EmployeeAvailabilityStatus::ApprovedNotCheckedIn, 'session' => null, 'reason' => 'Approved field work has no recorded check-in.'];
        }

        return ['status' => EmployeeAvailabilityStatus::Unknown, 'session' => null, 'reason' => 'No attendance, leave, training, or travel source is installed.'];
    }

    /** @return Collection<int, FieldWorkSession> */
    public function fieldWorkSessionsForDate(Employee $employee, Carbon $date): Collection
    {
        $from = $date->copy()->startOfDay();
        $to = $date->copy()->endOfDay();

        return FieldWorkSession::query()
            ->with('request:id,reference_number')
            ->where('employee_id', $employee->id)
            ->where('approved_start_at', '<=', $to)
            ->where('approved_end_at', '>=', $from)
            ->orderBy('approved_start_at')
            ->get();
    }

    /**
     * Bounded, paginated manager/HR availability list. It exposes destination
     * summary only and never location-event coordinates.
     */
    public function getTeamAvailability(User $actor, Carbon $at, int $perPage = 25): LengthAwarePaginator
    {
        // Availability uses organization scope, not a role name. A future
        // reporting-line model may add a supervisor-only bounded query.
        abort_unless($actor->can('field_work.view_team_availability') && $actor->can('field_work.view_scoped'), 403);

        $query = FieldWorkParticipant::query()
            ->with([
                'employee:id,employee_number,full_name,name_en,current_assignment_id',
                'request:id,reference_number,destination_location,external_organization_name,expected_return_at,status',
                'session',
            ])
            ->where('status', 'active')
            ->whereHas('request', fn ($requests) => $requests
                ->where('status', FieldWorkStatus::Approved->value)
                ->where('starts_at', '<=', $at)
                ->where('expected_return_at', '>=', $at));

        $this->scope->applyOrganizationScope($query, $actor, 'organization_id');

        return $query->orderBy('organization_id')->orderBy('employee_id')->paginate(min(max($perPage, 1), 100));
    }

    /** @return array{status:string,reference_number:?string,destination_summary:?string,expected_return_at:?string} */
    public function explainStatus(FieldWorkParticipant $participant, Carbon $at): array
    {
        $participant->loadMissing('session', 'request');
        $state = $this->getEmployeeStatusAt($participant->employee, $at);
        $request = $participant->request;

        return [
            'status' => $state['status']->value,
            'reference_number' => $request?->reference_number,
            'destination_summary' => $request?->destination_location ?? $request?->external_organization_name,
            'expected_return_at' => $request?->expected_return_at?->toIso8601String(),
        ];
    }
}
