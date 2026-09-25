<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Enums\AssignmentStatus;
use App\Models\DailyActivityLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionService;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds every management-side query in the module from one place, so the
 * register, the review queue, the dashboards and the exports cannot disagree
 * about who is in scope.
 *
 * Organization scope is enforced here, on the server, against the log's
 * snapshot organization. Filter values from the request only ever NARROW the
 * result; an organization_id outside scope simply matches nothing.
 */
class DailyActivityQueryService
{
    public const MAX_RANGE_DAYS = 92;

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly DailyActivityReviewerResolver $reviewers,
    ) {}

    /** Organization-scope oversight granted by view_scoped. */
    public function oversightCoverage(User $user, string $permission = 'daily_activities.view_scoped'): DailyActivityCoverage
    {
        if (! $user->can($permission)) {
            return DailyActivityCoverage::none();
        }

        if ($this->scope->isUnrestricted($user)) {
            return new DailyActivityCoverage(all: true);
        }

        return new DailyActivityCoverage(organizationIds: $this->scope->allowedOrganizationIds($user));
    }

    /** Employees the user reviews, granted by view_team + an assignment. */
    public function teamCoverage(User $user): DailyActivityCoverage
    {
        return $user->can('daily_activities.view_team')
            ? $this->reviewers->coverage($user)
            : DailyActivityCoverage::none();
    }

    /** Everything the user may see in the register. */
    public function visibleCoverage(User $user): DailyActivityCoverage
    {
        return $this->oversightCoverage($user)->merge($this->teamCoverage($user));
    }

    /** Population for dashboards and reports. */
    public function reportCoverage(User $user): DailyActivityCoverage
    {
        return $this->oversightCoverage($user, 'daily_activities.view_reports')->merge($this->teamCoverage($user));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<DailyActivityLog>
     */
    public function logs(DailyActivityCoverage $coverage, array $filters): Builder
    {
        $query = $coverage->apply(DailyActivityLog::query());

        if (! empty($filters['date_from'])) {
            $query->where('activity_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('activity_date', '<=', $filters['date_to'].' 23:59:59');
        }
        foreach (['organization_id', 'organization_unit_id', 'position_id', 'employee_id'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (($filters['late'] ?? '') !== '' && $filters['late'] !== null) {
            $query->where('is_late', filter_var($filters['late'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['position_service_id'])) {
            $query->whereHas('items', fn (Builder $items) => $items->where('position_service_id', $filters['position_service_id']));
        }
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->whereHas('employee', fn (Builder $employee) => $employee
                ->where('full_name', 'like', $term)
                ->orWhere('name_en', 'like', $term)
                ->orWhere('employee_number', 'like', $term));
        }

        return $query;
    }

    /**
     * Employees whose placement overlaps the range inside the coverage.
     * Day-level scope is re-applied by the calendar service through
     * DailyActivityCoverage::matchesAssignment, so a transfer mid-range
     * counts each day under the organization it belonged to.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Employee>
     */
    public function employeesInCoverage(DailyActivityCoverage $coverage, Carbon $from, Carbon $to, array $filters = []): Collection
    {
        if ($coverage->isEmpty()) {
            return collect();
        }

        $assignments = $coverage->apply(EmployeeAssignment::query())
            ->where('assignment_status', '!=', AssignmentStatus::PendingTransfer->value)
            ->whereDate('effective_from', '<=', $to->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from->toDateString()));

        foreach (['organization_id', 'organization_unit_id', 'position_id', 'employee_id'] as $column) {
            if (! empty($filters[$column])) {
                $assignments->where($column, $filters[$column]);
            }
        }

        $employees = Employee::query()->whereIn('id', $assignments->select('employee_id'));

        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $employees->where(fn (Builder $q) => $q
                ->where('full_name', 'like', $term)
                ->orWhere('name_en', 'like', $term)
                ->orWhere('employee_number', 'like', $term));
        }

        return $employees->orderBy('full_name')->get(['id', 'employee_number', 'full_name', 'name_en', 'status', 'email']);
    }

    /**
     * Day-level scope predicate matching the coverage plus the unit /
     * position / organization filters.
     *
     * @param  array<string, mixed>  $filters
     * @return callable(EmployeeAssignment): bool
     */
    public function assignmentPredicate(DailyActivityCoverage $coverage, array $filters): callable
    {
        return static function (EmployeeAssignment $assignment) use ($coverage, $filters): bool {
            if (! $coverage->matchesAssignment($assignment)) {
                return false;
            }
            foreach (['organization_id', 'organization_unit_id', 'position_id'] as $column) {
                if (! empty($filters[$column]) && $assignment->{$column} !== $filters[$column]) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * Filter dropdown options, limited to the coverage.
     *
     * @return array<string, mixed>
     */
    public function filterOptions(DailyActivityCoverage $coverage, ?string $organizationId): array
    {
        $organizations = $coverage->isEmpty() ? collect() : Organization::query()
            ->when(! $coverage->all, fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->whereIn('id', $coverage->organizationIds)
                ->orWhereIn('id', OrganizationUnit::query()->whereIn('id', $coverage->unitIds)->select('organization_id'))
                ->orWhereIn('id', EmployeeAssignment::query()->whereIn('employee_id', $coverage->employeeIds)->where('is_current', true)->select('organization_id'))))
            ->orderBy('name_en')
            ->limit(500)
            ->get(['id', 'name_en', 'name_am']);

        $organizationId ??= $organizations->count() === 1 ? (string) $organizations->first()->id : null;
        $organizationAllowed = $organizationId !== null && $organizations->contains('id', $organizationId);

        return [
            'organizations' => $organizations->map(fn (Organization $o): array => ['id' => $o->id, 'name_en' => $o->name_en, 'name_am' => $o->name_am])->values()->all(),
            'units' => $organizationAllowed
                ? OrganizationUnit::query()->where('organization_id', $organizationId)
                    ->when(! $coverage->all && ! in_array($organizationId, $coverage->organizationIds, true), fn (Builder $q) => $q->whereIn('id', $coverage->unitIds))
                    ->orderBy('name_en')->get(['id', 'name_en', 'name_am'])
                    ->map(fn (OrganizationUnit $u): array => ['id' => $u->id, 'name_en' => $u->name_en, 'name_am' => $u->name_am])->all()
                : [],
            'positions' => $organizationAllowed
                ? Position::query()->where('organization_id', $organizationId)->orderBy('title_en')->limit(1000)->get(['id', 'title_en', 'title_am'])
                    ->map(fn (Position $p): array => ['id' => $p->id, 'name_en' => $p->title_en, 'name_am' => $p->title_am])->all()
                : [],
            'services' => $organizationAllowed
                ? PositionService::query()->where('organization_id', $organizationId)->where('is_active', true)->orderBy('name_en')->limit(1000)->get(['id', 'name_en', 'name_am'])
                    ->map(fn (PositionService $s): array => ['id' => $s->id, 'name_en' => $s->name_en, 'name_am' => $s->name_am])->all()
                : [],
        ];
    }

    /**
     * Normalise a date range from the request, clamped to MAX_RANGE_DAYS so a
     * report cannot be asked to evaluate years of employee-days at once.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function range(?string $from, ?string $to, Carbon $today, int $defaultDays = 0): array
    {
        $end = $this->parseDate($to) ?? $today->copy();
        $start = $this->parseDate($from) ?? $end->copy()->subDays($defaultDays);

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        if ($start->diffInDays($end) > self::MAX_RANGE_DAYS - 1) {
            $start = $end->copy()->subDays(self::MAX_RANGE_DAYS - 1);
        }

        return [$start, $end];
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
