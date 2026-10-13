<?php

declare(strict_types=1);

namespace App\Services\Discipline;

use App\Enums\Discipline\DisciplinaryCaseRole;
use App\Models\DisciplinaryCase;
use App\Models\DisciplinaryCaseMember;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Record-level access for disciplinary cases: permission + organization scope
 * + case role, with the conflict rules of Regulation 201/2018:
 *
 *  - the accused never acts on their own case except to answer it;
 *  - a recused member (Art. 9) loses the seat for that case;
 *  - committee work needs a seat on the case, not just the permission.
 */
final class DisciplinaryCaseAccess
{
    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function isAccused(User $user, DisciplinaryCase $case): bool
    {
        return $user->employee_id !== null && $user->employee_id === $case->accused_employee_id;
    }

    /** The user's active, non-recused seat on the case. */
    public function seat(User $user, DisciplinaryCase $case): ?DisciplinaryCaseMember
    {
        if ($user->employee_id === null) {
            return null;
        }

        return $case->members()->seated()->where('employee_id', $user->employee_id)->first();
    }

    public function canView(User $user, DisciplinaryCase $case): bool
    {
        if ($this->isAccused($user, $case)) {
            return $user->can('discipline.view_own');
        }

        return $this->seat($user, $case) !== null
            || $this->scope->canExercisePermission($user, 'discipline.view', $case->organization_id);
    }

    /** Institution-side action under $permission: never by the accused, always in scope. */
    public function canOperate(User $user, DisciplinaryCase $case, string $permission): bool
    {
        return ! $this->isAccused($user, $case)
            && $this->scope->canExercisePermission($user, $permission, $case->organization_id);
    }

    /** Recording the investigation file: a seated committee member/investigator, or an HR case manager. */
    public function canInvestigate(User $user, DisciplinaryCase $case): bool
    {
        if ($this->isAccused($user, $case) || ! $user->can('discipline.investigate')) {
            return false;
        }

        return $this->seat($user, $case) !== null || $this->canOperate($user, $case, 'discipline.manage');
    }

    /** Adopting the recommendation: only a seated member (the chair or investigator records it). */
    public function canRecommend(User $user, DisciplinaryCase $case): bool
    {
        $seat = $this->seat($user, $case);

        return $seat !== null
            && ! $this->isAccused($user, $case)
            && $user->can('discipline.recommend')
            && in_array($seat->role, [DisciplinaryCaseRole::Chairperson, DisciplinaryCaseRole::Secretary, DisciplinaryCaseRole::Investigator], true);
    }

    /** The accused answering their own case (response, defence evidence, final statement). */
    public function canRespondAsAccused(User $user, DisciplinaryCase $case): bool
    {
        return $this->isAccused($user, $case) && $user->can('discipline.respond_own');
    }

    public function canCreateIn(User $user, ?string $organizationId): bool
    {
        return $this->scope->canExercisePermission($user, 'discipline.create', $organizationId);
    }

    /**
     * Cases visible in the administrative list: in scope or seated, never the
     * user's own case (the accused uses My Disciplinary Cases instead).
     *
     * @param  Builder<DisciplinaryCase>  $query
     * @return Builder<DisciplinaryCase>
     */
    public function constrainList(Builder $query, User $user): Builder
    {
        if ($user->employee_id !== null) {
            $query->where('accused_employee_id', '!=', $user->employee_id);
        }

        $canViewScope = $user->can('discipline.view');
        $allowed = $this->scope->isUnrestricted($user) ? null : $this->scope->allowedOrganizationIds($user);

        return $query->where(function (Builder $q) use ($user, $canViewScope, $allowed): void {
            if ($canViewScope) {
                $allowed === null ? $q->whereRaw('1 = 1') : $q->whereIn('organization_id', $allowed);
            } else {
                $q->whereRaw('1 = 0');
            }

            if ($user->employee_id !== null) {
                $q->orWhereHas('members', fn (Builder $m) => $m->seated()->where('employee_id', $user->employee_id));
            }
        });
    }

    public function authorize(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException(__('discipline.errors.forbidden'));
        }
    }
}
