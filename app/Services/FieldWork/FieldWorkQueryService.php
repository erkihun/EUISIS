<?php

declare(strict_types=1);

namespace App\Services\FieldWork;

use App\Enums\AssignmentStatus;
use App\Enums\FieldWorkDestinationType;
use App\Enums\FieldWorkStatus;
use App\Enums\FieldWorkSupervisorResolution;
use App\Models\EmployeeAssignment;
use App\Models\FieldWorkRequest;
use App\Models\FieldWorkType;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\DailyActivity\DailyActivityReviewerResolver;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * List queries and dashboard figures. Every query starts from what the actor
 * may see (FieldWorkAccess); request filters only narrow it. Figures are SQL
 * aggregates, never "load all and count".
 */
class FieldWorkQueryService
{
    public const FILTER_KEYS = ['status', 'flag', 'field_work_type_id', 'destination_type', 'organization_id', 'organization_unit_id', 'date_from', 'date_to', 'search'];

    public function __construct(
        private readonly FieldWorkAccess $access,
        private readonly FieldWorkSettings $settings,
        private readonly OrganizationScopeService $scope,
        private readonly DailyActivityReviewerResolver $reviewers,
    ) {}

    /** @return Builder<FieldWorkRequest> */
    public function visible(User $user): Builder
    {
        return $this->access->constrainVisible(FieldWorkRequest::query(), $user);
    }

    /** Management lists: team and/or oversight, never "own" alone. @return Builder<FieldWorkRequest> */
    public function managed(User $user): Builder
    {
        $team = $user->can('field_work.view_team');
        $oversight = $user->can('field_work.view_org');

        return FieldWorkRequest::query()->where(function (Builder $query) use ($user, $team, $oversight): void {
            $query->whereRaw('1 = 0');
            if ($team) {
                $query->orWhere(fn (Builder $t) => $this->access->constrainTeam($t, $user));
            }
            if ($oversight) {
                $query->orWhere(fn (Builder $o) => $this->access->constrainOversight($o, $user));
            }
        });
    }

    /** Open (approved / in-field) work of the user's own team only, never oversight. @return Builder<FieldWorkRequest> */
    public function team(User $user): Builder
    {
        return $this->access->constrainTeam(FieldWorkRequest::query(), $user)
            ->whereIn('status', FieldWorkStatus::openValues());
    }

    /** Requests waiting on this user as supervisor (or in their line-manager coverage). @return Builder<FieldWorkRequest> */
    public function approvalQueue(User $user): Builder
    {
        return $this->access->constrainTeam(FieldWorkRequest::query(), $user)
            ->where('status', FieldWorkStatus::PendingSupervisorApproval->value);
    }

    /**
     * @param  Builder<FieldWorkRequest>  $query
     * @param  array<string, string>  $filters
     * @return Builder<FieldWorkRequest>
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        if (isset($filters['status']) && FieldWorkStatus::tryFrom($filters['status']) !== null) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['destination_type']) && FieldWorkDestinationType::tryFrom($filters['destination_type']) !== null) {
            $query->where('destination_type', $filters['destination_type']);
        }
        foreach (['field_work_type_id', 'organization_id', 'organization_unit_id'] as $column) {
            if (isset($filters[$column]) && Str::isUuid($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        match ($filters['flag'] ?? null) {
            'overdue' => $query->overdue(),
            'check_in_missing' => $query->checkInMissing(),
            'supervisor_not_resolved' => $query->where('status', FieldWorkStatus::PendingSupervisorApproval->value)
                ->where('supervisor_resolution', FieldWorkSupervisorResolution::NotResolved->value),
            default => null,
        };

        [$from, $to] = $this->window($filters['date_from'] ?? null, $filters['date_to'] ?? null);
        if ($from !== null && $to !== null) {
            $query->overlapping($from, $to);
        }

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $term = '%'.addcslashes(trim($filters['search']), '%_\\').'%';
            $like = ci_like_operator();
            $query->where(fn (Builder $q) => $q
                ->where('reference_number', $like, $term)
                ->orWhereHas('requester', fn (Builder $e) => $e
                    ->where('employee_number', $like, $term)
                    ->orWhere('full_name', $like, $term)
                    ->orWhere('name_en', $like, $term)));
        }

        return $query;
    }

    /**
     * Local-date window, bounded to 366 days so no list or export can ask for
     * an unbounded scan.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public function window(?string $from, ?string $to): array
    {
        $timezone = $this->settings->timezone();
        $parse = static function (?string $value) use ($timezone): ?Carbon {
            if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return null;
            }
            try {
                return Carbon::createFromFormat('Y-m-d', $value, $timezone)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        };

        $start = $parse($from);
        $end = $parse($to);
        if ($start === null && $end === null) {
            return [null, null];
        }
        $start ??= $end->copy()->subDays(30);
        $end ??= $start->copy()->addDays(30);
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) > 366) {
            $end = $start->copy()->addDays(366);
        }

        return [$this->settings->storage($start), $this->settings->storage($end->copy()->endOfDay())];
    }

    /**
     * Real, scoped dashboard figures. Each one is a single COUNT over the
     * managed set (team ∪ oversight).
     *
     * @return array<string, int>
     */
    public function dashboardFigures(User $user): array
    {
        $now = now();
        $timezone = $this->settings->timezone();
        $dayStart = $this->settings->storage($now->copy()->setTimezone($timezone)->startOfDay());
        $dayEnd = $this->settings->storage($now->copy()->setTimezone($timezone)->endOfDay());
        $base = fn (): Builder => $this->managed($user);

        return [
            'team_members' => $this->teamMemberCount($user),
            'pending_approval' => $this->approvalQueue($user)->count(),
            'approved_today' => $base()->whereIn('status', FieldWorkStatus::authorisedValues())->whereBetween('decided_at', [$dayStart, $dayEnd])->count(),
            'in_field' => $base()->where('status', FieldWorkStatus::InField->value)->count(),
            'returning_today' => $base()->whereIn('status', FieldWorkStatus::openValues())->whereBetween('expected_return_at', [$dayStart, $dayEnd])->count(),
            'check_in_missing' => $base()->checkInMissing($now)->count(),
            'overdue' => $base()->overdue($now)->count(),
            'supervisor_not_resolved' => $base()->where('status', FieldWorkStatus::PendingSupervisorApproval->value)
                ->where('supervisor_resolution', FieldWorkSupervisorResolution::NotResolved->value)->count(),
            'completed_today' => $base()->where('status', FieldWorkStatus::Completed->value)->whereBetween('completed_at', [$dayStart, $dayEnd])->count(),
        ];
    }

    /** Employees currently placed in the user's team coverage or oversight scope. */
    public function teamMemberCount(User $user): int
    {
        $current = fn (): Builder => EmployeeAssignment::query()
            ->where('is_current', true)
            ->where('assignment_status', '!=', AssignmentStatus::PendingTransfer->value);

        $query = $current()->where(function (Builder $scoped) use ($user): void {
            $scoped->whereRaw('1 = 0');
            if ($user->can('field_work.view_team') && ! ($coverage = $this->reviewers->coverage($user))->isEmpty()) {
                $scoped->orWhere(fn (Builder $q) => $coverage->apply($q));
            }
            if ($user->can('field_work.view_org')) {
                $scoped->orWhere(fn (Builder $q) => $this->scope->isUnrestricted($user)
                    ? $q->whereRaw('1 = 1')
                    : $q->whereIn('organization_id', $this->scope->allowedOrganizationIds($user)));
            }
        });

        return $query->distinct()->count('employee_id');
    }

    /** @return array<string, mixed> */
    public function filterOptions(User $user, ?string $organizationId): array
    {
        $organizations = $user->can('field_work.view_org')
            ? $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')->orderBy('name_en')->limit(500)->get(['id', 'name_en', 'name_am'])
            : collect();

        return [
            'types' => FieldWorkType::query()->ordered()->get(['id', 'name_en', 'name_am'])->map(fn (FieldWorkType $t): array => ['id' => $t->id, 'name_en' => $t->name_en, 'name_am' => $t->name_am])->all(),
            'statuses' => FieldWorkStatus::values(),
            'destination_types' => FieldWorkDestinationType::values(),
            'organizations' => $organizations->map(fn (Organization $o): array => ['id' => $o->id, 'name_en' => $o->name_en, 'name_am' => $o->name_am])->values()->all(),
            'units' => $organizationId !== null && $organizations->contains('id', $organizationId)
                ? OrganizationUnit::query()->where('organization_id', $organizationId)->orderBy('name_en')->limit(1000)->get(['id', 'name_en', 'name_am'])
                    ->map(fn (OrganizationUnit $u): array => ['id' => $u->id, 'name_en' => $u->name_en, 'name_am' => $u->name_am])->all()
                : [],
        ];
    }
}
