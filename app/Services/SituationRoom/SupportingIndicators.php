<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use Modules\DailyWork\Contracts\Data\DailyActivityStatus;
use App\Enums\EmployeeStatus;
use App\Enums\Performance\AgreementStatus;
use App\Models\Organization;
use App\Services\Dashboard\DashboardMetricService;
use Illuminate\Support\Facades\DB;
use Modules\FieldWork\Contracts\Data\FieldWorkStatus;
use Throwable;

/**
 * The supporting cards under the community voice: workforce, EPMS, daily
 * work, field work and cafeteria. Each card shows only to a user who already
 * holds that module's own view permission, counts the same institutions the
 * Situation Room is looking at, and fails on its own — a broken card returns
 * {error: true} and the rest of the page still loads. Counts only: no score
 * sums, no locations, no transport.
 */
final class SupportingIndicators
{
    /** Card => the existing permission that already lets a user see that module's numbers. */
    public const CARDS = [
        'workforce' => ['employees.view', 'positions.view'],
        'performance' => ['performance_reports.view'],
        'daily_work' => ['daily_activities.view_reports'],
        'field_work' => ['field_work.view_org'],
        'cafeteria' => ['cafeteria_reports.view'],
        // Approved and published design only; drafts never reach the Situation Room.
        'organization_design' => ['organization-design-reports.view'],
    ];

    public function __construct(private readonly DashboardMetricService $dashboard) {}

    /** @return array<string, array<string, mixed>> */
    public function cards(SituationRoomScope $scope): array
    {
        $cards = [];
        $organizationIds = null;

        foreach (self::CARDS as $card => $permissions) {
            if (! $this->allowed($scope, $permissions)) {
                continue;
            }

            try {
                $organizationIds ??= $this->organizationIds($scope);
                $cards[$card] = $this->{$card}($scope, $organizationIds);
            } catch (Throwable $e) {
                report($e);
                $cards[$card] = ['error' => true];
            }
        }

        return $cards;
    }

    /** @param  list<string>  $permissions */
    private function allowed(SituationRoomScope $scope, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $scope->user->can($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The institutions in view, or null for the whole city.
     *
     * @return list<string>|null
     */
    private function organizationIds(SituationRoomScope $scope): ?array
    {
        if ($scope->isUnrestricted() && $scope->node === null) {
            return null;
        }

        $query = Organization::query()->select('organizations.id');
        $scope->apply($query, 'organizations.id');

        return $query->pluck('organizations.id')->map(fn ($id): string => (string) $id)->all();
    }

    /** @param  list<string>|null  $ids */
    private function dashboardScope(?array $ids): array
    {
        return ['global_access' => $ids === null, 'organization_ids' => $ids ?? []];
    }

    /** @param  list<string>|null  $ids */
    private function workforce(SituationRoomScope $scope, ?array $ids): array
    {
        if ($ids === []) {
            return ['employees' => 0, 'active_employees' => 0, 'occupied' => 0, 'vacant' => 0];
        }
        $dashboardScope = $this->dashboardScope($ids);

        return [
            'employees' => $this->dashboard->employeeTotalCount($dashboardScope),
            'active_employees' => $this->dashboard->employeeStatusCount($dashboardScope, EmployeeStatus::Active->value),
        ] + $this->dashboard->positionOccupancyCounts($dashboardScope);
    }

    /** @param  list<string>|null  $ids */
    private function performance(SituationRoomScope $scope, ?array $ids): array
    {
        $rows = DB::table('employee_performance_agreements')
            ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $count = fn (array $statuses): int => (int) collect($statuses)->sum(fn (AgreementStatus $s): int => (int) ($rows[$s->value] ?? 0));

        return [
            'awaiting_agreement' => $count([AgreementStatus::Draft, AgreementStatus::PendingEmployeeReview, AgreementStatus::PendingManagerApproval, AgreementStatus::Returned]),
            'agreed' => $count([AgreementStatus::Agreed, AgreementStatus::Active]),
            'under_review' => $count([AgreementStatus::UnderReview]),
            'finalized' => $count([AgreementStatus::Finalized, AgreementStatus::Closed]),
        ];
    }

    /** @param  list<string>|null  $ids */
    private function daily_work(SituationRoomScope $scope, ?array $ids): array
    {
        $since = now()->subDays(6)->toDateString();
        $rows = DB::table('daily_activity_logs')
            ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
            ->where('activity_date', '>=', $since)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $value = fn (DailyActivityStatus ...$statuses): int => array_sum(array_map(fn (DailyActivityStatus $s): int => (int) ($rows[$s->value] ?? 0), $statuses));

        return [
            'days' => 7,
            'awaiting_review' => $value(DailyActivityStatus::Submitted, DailyActivityStatus::UnderReview, DailyActivityStatus::Resubmitted),
            'returned' => $value(DailyActivityStatus::ReturnedForCorrection),
            'approved' => $value(DailyActivityStatus::Approved),
        ];
    }

    /** @param  list<string>|null  $ids */
    private function field_work(SituationRoomScope $scope, ?array $ids): array
    {
        $rows = DB::table('field_work_requests')
            ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
            ->whereIn('status', [FieldWorkStatus::PendingSupervisorApproval->value, FieldWorkStatus::Approved->value, FieldWorkStatus::InField->value])
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'awaiting_approval' => (int) ($rows[FieldWorkStatus::PendingSupervisorApproval->value] ?? 0),
            'approved' => (int) ($rows[FieldWorkStatus::Approved->value] ?? 0),
            'in_field' => (int) ($rows[FieldWorkStatus::InField->value] ?? 0),
        ];
    }

    /** @param  list<string>|null  $ids */
    private function cafeteria(SituationRoomScope $scope, ?array $ids): array
    {
        $query = DB::table('cafeteria_transactions')
            ->where('cafeteria_transactions.transaction_date', now()->toDateString())
            ->where('cafeteria_transactions.status', 'accepted');

        if ($ids !== null) {
            // Placed by the employee's current institution, as the dashboard does.
            $query->join('employees', 'employees.id', '=', 'cafeteria_transactions.employee_id')
                ->join('employee_assignments as cafeteria_scope', 'cafeteria_scope.id', '=', 'employees.current_assignment_id')
                ->whereIn('cafeteria_scope.organization_id', $ids);
        }

        return ['meals_today' => (int) $query->count()];
    }

    /**
     * Structure readiness for the institutions in view: required FTE and
     * approved headcount of approved manpower plans (side by side, never
     * merged), active positions without a published job description, and
     * institutions with a published legacy baseline.
     *
     * @param  list<string>|null  $ids
     */
    private function organization_design(SituationRoomScope $scope, ?array $ids): array
    {
        $within = fn ($query, string $column = 'organization_id') => $ids === null ? $query : $query->whereIn($column, $ids);
        $lines = $within(DB::table('manpower_plan_lines')->whereIn('plan_id', DB::table('manpower_plans')->where('status', 'approved')->select('id')));
        $positions = (int) $within(DB::table('positions')->where('is_active', true))->count();
        $withJd = (int) $within(DB::table('job_descriptions')->where('status', 'published')->whereNull('deleted_at'))->distinct()->count('position_id');

        return [
            'required_fte' => (float) (clone $lines)->sum('calculated_fte'),
            'approved_headcount' => (int) (clone $lines)->sum('approved_headcount'),
            'positions_without_jd' => max(0, $positions - $withJd),
            'baselines_published' => (int) $within(DB::table('legacy_baselines')->where('status', 'published')->whereNull('deleted_at'))->distinct()->count('organization_id'),
        ];
    }
}
