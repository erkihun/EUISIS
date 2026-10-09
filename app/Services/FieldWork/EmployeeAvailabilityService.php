<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Enums\EmployeeAvailabilityStatus;
use App\Enums\FieldWorkStatus;
use App\Models\Employee;
use App\Models\FieldWorkParticipant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Scoped operational availability (docs/employee-availability.md): workflow
 * status, deliberately not live location and not attendance.
 *
 * Only statuses that installed EUISIS data can substantiate:
 *   OFFICIAL_FIELD_WORK      checked in to authorised field work, not yet out
 *   APPROVED_NOT_CHECKED_IN  approved field work covers the instant, no check-in
 *   UNKNOWN                  everything else — NOT "absent" or "in office":
 *                            no attendance, leave, training or travel source
 *                            exists that could prove either
 */
final class EmployeeAvailabilityService
{
    public function __construct(private readonly FieldWorkAccess $access) {}

    /** @return array{status: EmployeeAvailabilityStatus, participant: ?FieldWorkParticipant} */
    public function statusAt(Employee $employee, Carbon $at): array
    {
        $participant = $this->covering($at)->where('employee_id', $employee->id)->latest('checked_in_at')->first();

        return ['status' => $participant === null ? EmployeeAvailabilityStatus::Unknown : $this->statusOf($participant, $at), 'participant' => $participant];
    }

    public function statusOf(FieldWorkParticipant $participant, Carbon $at): EmployeeAvailabilityStatus
    {
        if ($participant->checked_in_at !== null && $participant->checked_in_at->lte($at)
            && ($participant->checked_out_at === null || $participant->checked_out_at->gt($at))) {
            return EmployeeAvailabilityStatus::OfficialFieldWork;
        }

        return $participant->checked_in_at === null ? EmployeeAvailabilityStatus::ApprovedNotCheckedIn : EmployeeAvailabilityStatus::Unknown;
    }

    /**
     * Employees on authorised field work at $at that the actor supervises
     * (line-manager coverage) or oversees (organization scope). Paginated,
     * at most 100 per page; never location-event coordinates.
     */
    public function teamAvailability(User $actor, Carbon $at, int $perPage = 25): LengthAwarePaginator
    {
        $team = $actor->can('field_work.view_team');
        $oversight = $actor->can('field_work.view_org');

        return $this->covering($at)
            ->whereHas('request', fn (Builder $request) => $request->where(function (Builder $scoped) use ($actor, $team, $oversight): void {
                $scoped->whereRaw('1 = 0');
                if ($team) {
                    $scoped->orWhere(fn (Builder $q) => $this->access->constrainTeam($q, $actor));
                }
                if ($oversight) {
                    $scoped->orWhere(fn (Builder $q) => $this->access->constrainOversight($q, $actor));
                }
            }))
            ->with([
                'employee:id,employee_number,full_name,name_en',
                'request:id,reference_number,status,destination_type,destination_organization_id,external_organization_name,site_name,destination_address,expected_return_at',
                'request.destinationOrganization:id,name_en,name_am',
                'organizationUnit:id,name_en,name_am',
            ])
            ->orderBy('organization_id')
            ->orderBy('employee_id')
            ->paginate(min(max($perPage, 1), 100))
            ->withQueryString();
    }

    /** Participants whose approved / in-field request window covers $at. @return Builder<FieldWorkParticipant> */
    private function covering(Carbon $at): Builder
    {
        return FieldWorkParticipant::query()->whereHas('request', fn (Builder $request) => $request
            ->whereIn('status', FieldWorkStatus::openValues())
            ->where('starts_at', '<=', $at)
            ->where('expected_return_at', '>', $at));
    }
}
