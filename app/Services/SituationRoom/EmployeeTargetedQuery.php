<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use App\Models\Employee;
use App\Models\EmployeeServiceFeedback;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Employee-targeted public submissions" inside an institution the leader may
 * see: counts per employee, and one employee's submissions.
 *
 * A count is not a judgement: there is no ranking title, no photo, no
 * personal data beyond the name, and the default order is the most recent
 * submission, not the largest count. A submission stays where it was
 * recorded (its submission-time institution), so a transferred employee's
 * earlier submissions remain with the institution they served then.
 */
final class EmployeeTargetedQuery
{
    private const T = 'employee_service_feedback';

    public const PER_PAGE = 25;

    public function __construct(private readonly CommunityVoiceQuery $voice) {}

    /** @return Builder<EmployeeServiceFeedback> */
    private function employeeRows(SituationRoomScope $scope, SituationRoomFilters $filters): Builder
    {
        return $this->voice->base($scope, $filters, $filters->start, $filters->end)
            ->whereNotNull(self::T.'.employee_id')
            ->where(fn (Builder $q) => $q->where(self::T.'.target_type', 'employee')->orWhereNull(self::T.'.target_type'));
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, sort: string}
     */
    public function summary(SituationRoomScope $scope, SituationRoomFilters $filters, int $page = 1, string $sort = 'latest'): array
    {
        $sort = $sort === 'total' ? 'total' : 'latest';
        $page = max(1, $page);

        $total = (int) $this->employeeRows($scope, $filters)->toBase()->distinct()->count(self::T.'.employee_id');

        $rows = $this->employeeRows($scope, $filters)->toBase()
            ->selectRaw(self::T.'.employee_id AS employee_id')
            ->selectRaw($this->voice->countColumns())
            ->selectRaw('MAX('.self::T.'.submitted_at) AS latest_at')
            ->groupBy(self::T.'.employee_id')
            ->when($sort === 'total', fn ($q) => $q->orderByDesc('total')->orderByDesc('latest_at'), fn ($q) => $q->orderByDesc('latest_at'))
            ->orderBy(self::T.'.employee_id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->get();

        $employees = Employee::query()->whereIn('id', $rows->pluck('employee_id')->all())->get(['id', 'full_name', 'name_en', 'metadata'])->keyBy(fn ($e): string => (string) $e->id);

        return [
            'rows' => $rows->map(function ($row) use ($employees): array {
                $employee = $employees[(string) $row->employee_id] ?? null;

                return [
                    'id' => (string) $row->employee_id,
                    'name_en' => $employee?->fullNameEn(),
                    'name_am' => $employee?->fullNameAm(),
                    'latest_at' => $row->latest_at ? substr((string) $row->latest_at, 0, 19) : null,
                ] + $this->voice->countsFrom($row);
            })->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'sort' => $sort,
        ];
    }

    /**
     * One employee's submissions in scope. The narrative (subject and message)
     * is included only with the detail grant; submitter identity and
     * attachments never travel here — the submission's own page applies those
     * grants.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function submissions(SituationRoomScope $scope, SituationRoomFilters $filters, Employee $employee, bool $withNarrative, int $page = 1): array
    {
        $page = max(1, $page);
        $query = $this->employeeRows($scope, $filters)->where(self::T.'.employee_id', $employee->getKey());
        $total = (clone $query)->count();

        $items = $query
            ->orderByDesc(self::T.'.submitted_at')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->get([self::T.'.id', self::T.'.type', self::T.'.status', self::T.'.submitted_at', self::T.'.resolved_at', self::T.'.organization_id', self::T.'.organization_unit_id', self::T.'.service_name_snapshot', self::T.'.reference_number', self::T.'.rating', self::T.'.subject', self::T.'.comment']);

        $organizations = Organization::query()->whereIn('id', $items->pluck('organization_id')->filter()->all())->get(['id', 'name_en', 'name_am'])->keyBy(fn ($o): string => (string) $o->id);

        return [
            'rows' => $items->map(function (EmployeeServiceFeedback $item) use ($organizations, $withNarrative): array {
                $organization = $item->organization_id ? ($organizations[(string) $item->organization_id] ?? null) : null;
                $status = $item->status->value;

                return [
                    'id' => (string) $item->id,
                    'type' => $item->type->value,
                    'status' => $status,
                    'resolved' => in_array($status, SituationRoomMetrics::RESOLVED_STATUSES, true),
                    'submitted_at' => $item->submitted_at?->toIso8601String(),
                    'resolved_at' => $item->resolved_at?->toIso8601String(),
                    'reference' => $item->reference_number,
                    'rating' => $item->rating,
                    // Where it was recorded when submitted (submission-time context).
                    'organization_en' => $organization?->name_en,
                    'organization_am' => $organization?->name_am,
                    'service' => $item->service_name_snapshot,
                    'subject' => $withNarrative ? $item->subject : null,
                    'message' => $withNarrative ? $item->comment : null,
                ];
            })->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => self::PER_PAGE,
        ];
    }
}
