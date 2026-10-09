<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Enums\FieldWorkStatus;
use App\Models\Employee;
use App\Models\FieldWorkParticipant;
use App\Models\FieldWorkRequest;
use App\Models\User;
use App\Services\DailyActivity\DailyActivityReviewerResolver;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and do what in Field Work (docs/field-work-security.md).
 * Server-side only; the frontend mirrors it. Always checked against the
 * RECORD (its snapshot organization / unit / participants), never against
 * ids the request supplied.
 *
 *   own        the user's linked employee is a participant (the requester is
 *              the lead participant)
 *   team       field_work.view_team AND either the request's resolved
 *              supervisor, or a line-manager (reviewer) assignment covering
 *              a participant's snapshot placement
 *   oversight  field_work.view_org inside OrganizationScopeService scope of
 *              the request's SOURCE organization. The destination
 *              organization gains nothing: hosting field work does not open
 *              the visitor's HR record.
 *
 * Exact GPS needs field_work.location.view_precise on top of visibility.
 */
class FieldWorkAccess
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly DailyActivityReviewerResolver $reviewers,
        private readonly FieldWorkSupervisorResolver $supervisors,
    ) {}

    public function employeeOf(User $user): ?Employee
    {
        $employee = $user->employee;

        return $employee instanceof Employee ? $employee : null;
    }

    public function isRequester(User $user, FieldWorkRequest $request): bool
    {
        $employee = $this->employeeOf($user);

        return $employee !== null && $employee->id === $request->requester_employee_id;
    }

    public function participantFor(User $user, FieldWorkRequest $request): ?FieldWorkParticipant
    {
        $employee = $this->employeeOf($user);
        if ($employee === null) {
            return null;
        }

        return $request->relationLoaded('participants')
            ? $request->participants->firstWhere('employee_id', $employee->id)
            : $request->participants()->where('employee_id', $employee->id)->first();
    }

    public function isParticipant(User $user, FieldWorkRequest $request): bool
    {
        return $this->participantFor($user, $request) !== null;
    }

    public function canView(User $user, FieldWorkRequest $request): bool
    {
        if ($this->isParticipant($user, $request) && $user->can('field_work.view_own')) {
            return true;
        }

        return $this->canViewAsTeam($user, $request) || $this->canViewAsOversight($user, $request);
    }

    public function canViewAsTeam(User $user, FieldWorkRequest $request): bool
    {
        if (! $user->can('field_work.view_team')) {
            return false;
        }
        if ($request->supervisor_user_id !== null && (int) $request->supervisor_user_id === (int) $user->getKey()) {
            return true;
        }

        return $this->reviewers->coverage($user)
            ->apply(FieldWorkParticipant::query()->where('field_work_request_id', $request->id))
            ->exists();
    }

    public function canViewAsOversight(User $user, FieldWorkRequest $request): bool
    {
        return $user->can('field_work.view_org')
            && $this->scope->canAccessOrganization($user, $request->organization_id);
    }

    /** Decide (approve / return / reject): the permission AND the live supervisor identity. */
    public function canDecide(User $user, FieldWorkRequest $request, string $permission): bool
    {
        return $request->status === FieldWorkStatus::PendingSupervisorApproval
            && $user->can($permission)
            && $this->supervisors->isSupervisorOf($user, $request);
    }

    public function canViewLocationStatus(User $user, FieldWorkRequest $request): bool
    {
        return $this->canView($user, $request)
            && ($this->isParticipant($user, $request) || $user->can('field_work.location.view_status'));
    }

    public function canViewPreciseLocation(User $user, FieldWorkRequest $request): bool
    {
        return $user->can('field_work.location.view_precise')
            && $this->scope->canAccessOrganization($user, $request->organization_id)
            && $this->canView($user, $request);
    }

    /**
     * Every request the user may see. A user with none of the three routes
     * gets an impossible predicate, never "everything".
     *
     * @param  Builder<FieldWorkRequest>  $query
     * @return Builder<FieldWorkRequest>
     */
    public function constrainVisible(Builder $query, User $user): Builder
    {
        $employee = $this->employeeOf($user);
        $own = $employee !== null && $user->can('field_work.view_own');
        $team = $user->can('field_work.view_team');
        $oversight = $user->can('field_work.view_org');

        return $query->where(function (Builder $scoped) use ($user, $employee, $own, $team, $oversight): void {
            $scoped->whereRaw('1 = 0');
            if ($own) {
                $scoped->orWhereHas('participants', fn (Builder $p) => $p->where('employee_id', $employee->id));
            }
            if ($team) {
                $scoped->orWhere(fn (Builder $t) => $this->applyTeam($t, $user));
            }
            if ($oversight) {
                $scoped->orWhere(fn (Builder $o) => $this->applyOversight($o, $user));
            }
        });
    }

    /** @param Builder<FieldWorkRequest> $query */
    public function constrainTeam(Builder $query, User $user): Builder
    {
        if (! $user->can('field_work.view_team')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $t) => $this->applyTeam($t, $user));
    }

    /** @param Builder<FieldWorkRequest> $query */
    public function constrainOversight(Builder $query, User $user): Builder
    {
        if (! $user->can('field_work.view_org')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $o) => $this->applyOversight($o, $user));
    }

    /** @param Builder<FieldWorkRequest> $query */
    private function applyTeam(Builder $query, User $user): void
    {
        $coverage = $this->reviewers->coverage($user);

        $query->where('supervisor_user_id', $user->getKey());
        if (! $coverage->isEmpty()) {
            $query->orWhereHas('participants', fn (Builder $p) => $coverage->apply($p));
        }
    }

    /** @param Builder<FieldWorkRequest> $query */
    private function applyOversight(Builder $query, User $user): void
    {
        if ($this->scope->isUnrestricted($user)) {
            $query->whereRaw('1 = 1');

            return;
        }

        $query->whereIn('organization_id', $this->scope->allowedOrganizationIds($user));
    }
}
