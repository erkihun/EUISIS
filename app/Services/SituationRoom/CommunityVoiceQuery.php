<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use App\Models\EmployeeServiceFeedback;
use App\Models\Organization;
use App\Models\PublicFeedbackCategory;
use App\Services\ServiceFeedback\PublicFeedbackVisibility;
use App\Support\Database\SqlDateBucket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * "What is the community saying?" — every Situation Room figure about public
 * feedback, suggestions and complaints, computed in the database with grouped
 * queries. Each query starts from what the user may see (target-type grants,
 * never their own submissions, organization scope, the selected institution
 * and its descendants) before anything is counted.
 */
final class CommunityVoiceQuery
{
    private const T = 'employee_service_feedback';

    public function __construct(private readonly PublicFeedbackVisibility $visibility) {}

    /**
     * Visible, in-scope submissions, narrowed by the filters and (unless null) a date range.
     *
     * @return Builder<EmployeeServiceFeedback>
     */
    public function base(SituationRoomScope $scope, SituationRoomFilters $filters, ?Carbon $from = null, ?Carbon $to = null): Builder
    {
        $query = $this->visibility->applyTargetScope(EmployeeServiceFeedback::query(), $scope->user);
        $query->whereNotIn(self::T.'.status', SituationRoomMetrics::EXCLUDED_STATUSES);

        if ($scope->user->employee_id !== null) {
            $employeeId = $scope->user->employee_id;
            $query->where(fn (Builder $q) => $q->whereNull(self::T.'.employee_id')->orWhere(self::T.'.employee_id', '!=', $employeeId));
        }

        $scope->apply($query);

        if ($filters->type !== null) {
            $query->where(self::T.'.type', $filters->type);
        }
        if ($filters->target === 'employee') {
            $query->where(fn (Builder $q) => $q->where(self::T.'.target_type', 'employee')->orWhereNull(self::T.'.target_type'));
        } elseif ($filters->target === 'organization') {
            $query->where(self::T.'.target_type', 'organization');
        }
        if ($filters->categoryId === 'none') {
            $query->whereNull(self::T.'.category_id');
        } elseif ($filters->categoryId !== null) {
            $query->where(self::T.'.category_id', $filters->categoryId);
        }
        if ($filters->status !== null) {
            $query->whereIn(self::T.'.status', SituationRoomMetrics::STATUS_BUCKETS[$filters->status]);
        }
        if ($from !== null) {
            $query->where(self::T.'.submitted_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where(self::T.'.submitted_at', '<', $to);
        }

        return $query;
    }

    /**
     * The headline counts for the period and the previous equivalent period.
     *
     * @return array{current: array<string, int|float|null>, previous: array<string, int|float|null>}
     */
    public function kpis(SituationRoomScope $scope, SituationRoomFilters $filters): array
    {
        $read = function (Carbon $from, Carbon $to) use ($scope, $filters): array {
            $row = $this->base($scope, $filters, $from, $to)->toBase()
                ->selectRaw($this->countColumns())
                ->selectRaw('AVG(CASE WHEN '.self::T.".type = 'feedback' THEN ".self::T.'.rating END) AS avg_rating')
                ->first();

            return $this->countsFrom($row) + ['avg_rating' => $row?->avg_rating !== null ? round((float) $row->avg_rating, 2) : null];
        };

        return [
            'current' => $read($filters->start, $filters->end),
            'previous' => $read($filters->previousStart, $filters->previousEnd),
        ];
    }

    /**
     * Feedback, suggestions and complaints per day, week or month.
     *
     * @return array{unit: string, points: list<array{bucket: string, feedback: int, suggestion: int, complaint: int}>}
     */
    public function trend(SituationRoomScope $scope, SituationRoomFilters $filters): array
    {
        $unit = $filters->bucketUnit();
        $bucket = SqlDateBucket::expression(self::T.'.submitted_at', $unit);

        $rows = $this->base($scope, $filters, $filters->start, $filters->end)->toBase()
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw($this->typeColumns())
            ->groupByRaw($bucket)
            ->orderByRaw($bucket)
            ->get()
            ->keyBy('bucket');

        // Every bucket in the period, so a quiet day reads as zero rather than a gap.
        $points = [];
        $cursor = match ($unit) {
            SqlDateBucket::WEEK => $filters->start->copy()->startOfWeek(),
            SqlDateBucket::MONTH => $filters->start->copy()->startOfMonth(),
            default => $filters->start->copy()->startOfDay(),
        };
        while ($cursor < $filters->end) {
            $key = $cursor->toDateString();
            $row = $rows[$key] ?? null;
            $points[] = ['bucket' => $key, 'feedback' => (int) ($row->feedback ?? 0), 'suggestion' => (int) ($row->suggestion ?? 0), 'complaint' => (int) ($row->complaint ?? 0)];
            $cursor = match ($unit) {
                SqlDateBucket::WEEK => $cursor->addWeek(),
                SqlDateBucket::MONTH => $cursor->addMonthNoOverflow(),
                default => $cursor->addDay(),
            };
        }

        return ['unit' => $unit, 'points' => $points];
    }

    /**
     * Counts per compared institution (each including everything under it)
     * plus the submissions recorded at the selected institution itself.
     *
     * @return array{rows: list<array<string, mixed>>, own: array<string, int>}
     */
    public function byInstitution(SituationRoomScope $scope, SituationRoomFilters $filters): array
    {
        $children = $scope->childIds();
        $byChild = collect();

        if ($children !== [] && $scope->versionId !== null) {
            $byChild = $this->base($scope, $filters, $filters->start, $filters->end)->toBase()
                ->join('organization_closure_paths as rollup', function ($join) use ($scope): void {
                    $join->on('rollup.descendant_organization_id', '=', self::T.'.organization_id')
                        ->where('rollup.hierarchy_version_id', '=', $scope->versionId);
                })
                ->whereIn('rollup.ancestor_organization_id', $children)
                ->selectRaw('rollup.ancestor_organization_id AS institution_id')
                ->selectRaw($this->countColumns())
                ->groupBy('rollup.ancestor_organization_id')
                ->get()
                ->keyBy(fn ($row): string => (string) $row->institution_id);
        }

        $own = $this->base($scope, $filters, $filters->start, $filters->end)->toBase();
        if ($children !== [] && $scope->versionId !== null) {
            $own->whereNotExists(fn ($q) => $q->selectRaw('1')->from('organization_closure_paths as under_child')
                ->whereColumn('under_child.descendant_organization_id', self::T.'.organization_id')
                ->where('under_child.hierarchy_version_id', $scope->versionId)
                ->whereIn('under_child.ancestor_organization_id', $children));
        }
        $ownCounts = $this->countsFrom($own->selectRaw($this->countColumns())->first());

        $names = Organization::query()->whereIn('id', $children)->get(['id', 'code', 'name_en', 'name_am'])->keyBy(fn ($o): string => (string) $o->id);
        $rows = [];
        foreach ($children as $id) {
            if (! isset($names[$id])) {
                continue;
            }
            $rows[] = ['id' => $id, 'code' => $names[$id]->code, 'name_en' => $names[$id]->name_en, 'name_am' => $names[$id]->name_am] + $this->countsFrom($byChild[$id] ?? null);
        }
        usort($rows, fn (array $a, array $b): int => [$b['total'], $a['name_en']] <=> [$a['total'], $b['name_en']]);

        return ['rows' => $rows, 'own' => $ownCounts];
    }

    /**
     * Counts per approved category, uncategorized shown as its own row.
     *
     * @return list<array<string, mixed>>
     */
    public function byCategory(SituationRoomScope $scope, SituationRoomFilters $filters): array
    {
        $rows = $this->base($scope, $filters, $filters->start, $filters->end)->toBase()
            ->selectRaw(self::T.'.category_id AS category_id')
            ->selectRaw($this->countColumns())
            ->groupBy(self::T.'.category_id')
            ->get();

        $names = PublicFeedbackCategory::query()->whereIn('id', $rows->pluck('category_id')->filter()->all())->get(['id', 'name_en', 'name_am'])->keyBy(fn ($c): string => (string) $c->id);

        return $rows->map(fn ($row): array => [
            'id' => $row->category_id !== null ? (string) $row->category_id : null,
            'name_en' => $row->category_id !== null ? ($names[(string) $row->category_id]->name_en ?? null) : null,
            'name_am' => $row->category_id !== null ? ($names[(string) $row->category_id]->name_am ?? null) : null,
            'uncategorized' => $row->category_id === null,
        ] + $this->countsFrom($row))->sortByDesc('total')->values()->all();
    }

    /**
     * Counts per service the visitor named, as recorded at submission: the
     * position service (employee QR) or the institution catalog service
     * (organization feedback form). A service described only in words is not
     * linked and not counted.
     *
     * @return list<array<string, mixed>>
     */
    public function byService(SituationRoomScope $scope, SituationRoomFilters $filters): array
    {
        $service = 'COALESCE('.self::T.'.position_service_id, '.self::T.'.service_id)';

        return $this->base($scope, $filters, $filters->start, $filters->end)->toBase()
            ->where(fn ($query) => $query->whereNotNull(self::T.'.position_service_id')->orWhereNotNull(self::T.'.service_id'))
            ->selectRaw($service.' AS service_id, MAX('.self::T.'.service_no_snapshot) AS service_no, MAX('.self::T.'.service_name_snapshot) AS service_name')
            ->selectRaw($this->countColumns())
            ->groupByRaw($service)
            ->orderByDesc('total')
            ->limit(SituationRoomMetrics::TOP_N)
            ->get()
            ->map(fn ($row): array => ['id' => (string) $row->service_id, 'service_no' => $row->service_no, 'name' => $row->service_name] + $this->countsFrom($row))
            ->all();
    }

    /**
     * Where submissions stand in the workflow, and how many were never routed.
     *
     * @return array{statuses: array<string, int>, by_type: array<string, array<string, int>>}
     */
    public function resolution(SituationRoomScope $scope, SituationRoomFilters $filters): array
    {
        $rows = $this->base($scope, $filters, $filters->start, $filters->end)->toBase()
            ->selectRaw(self::T.'.status AS status, '.self::T.'.type AS type, COUNT(*) AS total')
            ->groupBy(self::T.'.status', self::T.'.type')
            ->get();

        $statuses = array_fill_keys(array_keys(SituationRoomMetrics::STATUS_BUCKETS), 0);
        $byType = array_fill_keys(SituationRoomMetrics::TYPES, ['unresolved' => 0, 'resolved' => 0]);
        foreach ($rows as $row) {
            foreach (SituationRoomMetrics::STATUS_BUCKETS as $bucket => $members) {
                if (in_array($row->status, $members, true)) {
                    $statuses[$bucket] += (int) $row->total;
                }
            }
            if (isset($byType[$row->type])) {
                $key = in_array($row->status, SituationRoomMetrics::RESOLVED_STATUSES, true) ? 'resolved' : 'unresolved';
                $byType[$row->type][$key] += (int) $row->total;
            }
        }

        return ['statuses' => $statuses, 'by_type' => $byType];
    }

    /**
     * Employee-targeted versus organization-targeted, per submission type.
     *
     * @return array<string, array<string, int>>
     */
    public function byTarget(SituationRoomScope $scope, SituationRoomFilters $filters): array
    {
        $rows = $this->base($scope, $filters, $filters->start, $filters->end)->toBase()
            ->selectRaw('COALESCE('.self::T.".target_type, 'employee') AS target")
            ->selectRaw($this->typeColumns())
            ->groupByRaw('COALESCE('.self::T.".target_type, 'employee')")
            ->get()
            ->keyBy('target');

        $result = [];
        foreach (SituationRoomMetrics::TARGETS as $target) {
            $row = $rows[$target] ?? null;
            $result[$target] = ['feedback' => (int) ($row->feedback ?? 0), 'suggestion' => (int) ($row->suggestion ?? 0), 'complaint' => (int) ($row->complaint ?? 0)];
        }

        return $result;
    }

    /**
     * The 1–5 ratings left with feedback: the positive voice as well as the critical.
     *
     * @return array{distribution: array<int, int>, average: ?float, total: int}
     */
    public function ratings(SituationRoomScope $scope, SituationRoomFilters $filters): array
    {
        $rows = $this->base($scope, $filters, $filters->start, $filters->end)->toBase()
            ->where(self::T.'.type', 'feedback')
            ->whereNotNull(self::T.'.rating')
            ->selectRaw(self::T.'.rating AS rating, COUNT(*) AS total')
            ->groupBy(self::T.'.rating')
            ->pluck('total', 'rating');

        $distribution = [];
        $sum = 0;
        $total = 0;
        foreach ([1, 2, 3, 4, 5] as $value) {
            $count = (int) ($rows[$value] ?? 0);
            $distribution[$value] = $count;
            $sum += $value * $count;
            $total += $count;
        }

        return ['distribution' => $distribution, 'average' => $total > 0 ? round($sum / $total, 2) : null, 'total' => $total];
    }

    /** COUNT(*) plus one conditional SUM per type and resolution state. */
    public function countColumns(): string
    {
        $t = self::T;

        return implode(', ', [
            'COUNT(*) AS total',
            $this->typeColumns(),
            "SUM(CASE WHEN {$t}.status IN (".SituationRoomMetrics::sqlList(SituationRoomMetrics::UNRESOLVED_STATUSES).') THEN 1 ELSE 0 END) AS unresolved',
            "SUM(CASE WHEN {$t}.status IN (".SituationRoomMetrics::sqlList(SituationRoomMetrics::RESOLVED_STATUSES).') THEN 1 ELSE 0 END) AS resolved',
            "SUM(CASE WHEN {$t}.status = 'unrouted' THEN 1 ELSE 0 END) AS unrouted",
            "SUM(CASE WHEN {$t}.type = 'complaint' AND {$t}.status IN (".SituationRoomMetrics::sqlList(SituationRoomMetrics::UNRESOLVED_STATUSES).') THEN 1 ELSE 0 END) AS unresolved_complaints',
        ]);
    }

    private function typeColumns(): string
    {
        $t = self::T;

        return implode(', ', array_map(
            fn (string $type): string => "SUM(CASE WHEN {$t}.type = '{$type}' THEN 1 ELSE 0 END) AS {$type}",
            SituationRoomMetrics::TYPES,
        ));
    }

    /** @return array{total: int, feedback: int, suggestion: int, complaint: int, unresolved: int, resolved: int, unrouted: int, unresolved_complaints: int} */
    public function countsFrom(?object $row): array
    {
        $value = fn (string $key): int => (int) ($row->{$key} ?? 0);

        return [
            'total' => $value('total'),
            'feedback' => $value('feedback'),
            'suggestion' => $value('suggestion'),
            'complaint' => $value('complaint'),
            'unresolved' => $value('unresolved'),
            'resolved' => $value('resolved'),
            'unrouted' => $value('unrouted'),
            'unresolved_complaints' => $value('unresolved_complaints'),
        ];
    }
}
