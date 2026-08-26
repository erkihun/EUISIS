<?php

declare(strict_types=1);

namespace App\Services\ServiceFeedback;

use App\Models\EmployeeServiceFeedback;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Filtering, scoping and aggregation for the admin feedback screens.
 *
 * Every query that reaches an administrator passes through `scopedQuery()`
 * first. That method is the single choke point for organization scoping: an
 * Organizational Admin sees only feedback recorded against organizations they
 * hold, and feedback with no organization on file is visible only to
 * unrestricted roles. Aggregations build on the same base query, so a scoped
 * admin's dashboard totals can never be inflated by rows they cannot open.
 */
class ServiceFeedbackQueryService
{
    public function __construct(private readonly OrganizationScopeService $scope) {}

    /**
     * Base query for a user, already confined to what they may see.
     *
     * @return Builder<EmployeeServiceFeedback>
     */
    public function scopedQuery(User $user): Builder
    {
        $query = EmployeeServiceFeedback::query();

        if ($this->scope->isUnrestricted($user)) {
            return $query;
        }

        $allowed = $this->scope->accessibleOrganizationIds($user);

        /*
         * whereIn on an empty list yields no rows, which is the correct
         * outcome: a scoped user with no accessible organizations sees
         * nothing rather than everything.
         *
         * Rows with a null organization are deliberately excluded here — there
         * is nothing to scope them against, so they stay with unrestricted
         * roles only. This mirrors EmployeeServiceFeedbackPolicy::withinScope().
         */
        return $query->whereIn('organization_id', $allowed->all());
    }

    /**
     * Apply the feedback list filters.
     *
     * @param  array<string, mixed>  $filters
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return Builder<EmployeeServiceFeedback>
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($this->filled($filters, 'organization_id'), fn (Builder $q): Builder => $q->where('organization_id', $filters['organization_id']))
            ->when($this->filled($filters, 'organization_unit_id'), fn (Builder $q): Builder => $q->where('organization_unit_id', $filters['organization_unit_id']))
            ->when($this->filled($filters, 'employee_id'), fn (Builder $q): Builder => $q->where('employee_id', $filters['employee_id']))
            ->when($this->filled($filters, 'service_type_id'), fn (Builder $q): Builder => $q->where('position_service_id', $filters['service_type_id']))
            ->when($this->filled($filters, 'service_no'), fn (Builder $q): Builder => $q->where('service_no_snapshot', $filters['service_no']))
            ->when($this->filled($filters, 'rating'), fn (Builder $q): Builder => $q->where('rating', (int) $filters['rating']))
            ->when($this->filled($filters, 'status'), fn (Builder $q): Builder => $q->where('status', $filters['status']))
            // Dates arrive as Y-m-d; `date_to` is inclusive of the whole day.
            ->when($this->filled($filters, 'date_from'), fn (Builder $q): Builder => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when($this->filled($filters, 'date_to'), fn (Builder $q): Builder => $q->whereDate('created_at', '<=', $filters['date_to']));
    }

    /**
     * Headline dashboard figures.
     *
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return array{total: int, average: float, low_rated: int, pending: int}
     */
    public function summary(Builder $query): array
    {
        $row = (clone $query)
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('AVG(rating) AS average_rating')
            ->first();

        return [
            'total' => (int) ($row->total_count ?? 0),
            // Rounded for display; reports that need precision recompute.
            'average' => round((float) ($row->average_rating ?? 0), 2),
            'low_rated' => (clone $query)->where('rating', '<=', 2)->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
        ];
    }

    /**
     * Count of submissions per star value, always covering 1..5.
     *
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return array<int, array{rating: int, count: int}>
     */
    public function ratingDistribution(Builder $query): array
    {
        $counts = (clone $query)
            ->select('rating', DB::raw('COUNT(*) AS total_count'))
            ->groupBy('rating')
            ->pluck('total_count', 'rating');

        // Missing star values are emitted as zero so the chart keeps five bars.
        return collect(range(1, 5))
            ->map(fn (int $star): array => [
                'rating' => $star,
                'count' => (int) ($counts[$star] ?? 0),
            ])
            ->all();
    }

    /**
     * Average rating and volume grouped by organization.
     *
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return Collection<int, array{id: string|null, name: string|null, total: int, average: float}>
     */
    public function byOrganization(Builder $query, int $limit = 10): Collection
    {
        return (clone $query)
            ->select('employee_service_feedback.organization_id')
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('AVG(rating) AS average_rating')
            ->with([])
            ->groupBy('employee_service_feedback.organization_id')
            ->orderByDesc('total_count')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->organization_id,
                'name' => $row->organization?->name_en,
                'total' => (int) $row->total_count,
                'average' => round((float) $row->average_rating, 2),
            ]);
    }

    /**
     * Average rating and volume grouped by employee.
     *
     * The employee's NAME is included here because this view is gated behind
     * `service_feedback.view` and organization scoping — unlike the public
     * page, an authorised administrator is entitled to see who was rated.
     *
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return Collection<int, array{id: string, name: string|null, employee_number: string|null, total: int, average: float}>
     */
    public function byEmployee(Builder $query, int $limit = 10): Collection
    {
        return (clone $query)
            ->select('employee_service_feedback.employee_id')
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('AVG(rating) AS average_rating')
            ->groupBy('employee_service_feedback.employee_id')
            ->orderByDesc('total_count')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->employee_id,
                'name' => $row->employee?->full_name,
                'employee_number' => $row->employee?->employee_number,
                'total' => (int) $row->total_count,
                'average' => round((float) $row->average_rating, 2),
            ]);
    }

    /**
     * Average rating and volume grouped by service type.
     *
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return Collection<int, array{id: string, name: string|null, total: int, average: float}>
     */
    public function byServiceType(Builder $query, int $limit = 20): Collection
    {
        return (clone $query)
            ->select('employee_service_feedback.position_service_id')
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('AVG(rating) AS average_rating')
            ->groupBy('employee_service_feedback.position_service_id')
            ->orderByDesc('total_count')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->position_service_id,
                'name' => $row->positionService?->name_en,
                'total' => (int) $row->total_count,
                'average' => round((float) $row->average_rating, 2),
            ]);
    }

    /**
     * Latest comments for the dashboard feed.
     *
     * Hidden entries are excluded: a comment suppressed for abuse or for
     * naming a third party must not resurface on the landing screen.
     *
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return Collection<int, EmployeeServiceFeedback>
     */
    public function recentComments(Builder $query, int $limit = 8): Collection
    {
        return (clone $query)
            ->visible()
            ->whereNotNull('comment')
            ->where('comment', '!=', '')
            ->with(['employee:id,full_name,employee_number', 'positionService:id,service_no,name_en,name_am', 'organization:id,name_en,name_am'])
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Per-employee averages for the reports screen.
     *
     * @param  Builder<EmployeeServiceFeedback>  $query
     * @return Collection<int, array<string, mixed>>
     */
    public function employeePerformance(Builder $query, int $minimumResponses = 1): Collection
    {
        return (clone $query)
            ->select('employee_service_feedback.employee_id')
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('AVG(rating) AS average_rating')
            ->selectRaw('SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) AS low_count')
            ->groupBy('employee_service_feedback.employee_id')
            ->havingRaw('COUNT(*) >= ?', [$minimumResponses])
            ->orderBy('average_rating')
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->employee_id,
                'name' => $row->employee?->full_name,
                'employee_number' => $row->employee?->employee_number,
                'total' => (int) $row->total_count,
                'average' => round((float) $row->average_rating, 2),
                'low_rated' => (int) $row->low_count,
            ]);
    }

    /** @param array<string, mixed> $filters */
    private function filled(array $filters, string $key): bool
    {
        return isset($filters[$key]) && (string) $filters[$key] !== '';
    }
}
