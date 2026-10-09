<?php

declare(strict_types=1);

namespace App\Services\Grievances;

use App\Enums\Grievance\GrievanceMovementType;
use App\Models\Grievance;
use App\Models\GrievanceCaseStage;
use App\Models\GrievanceCategory;
use App\Models\GrievanceDecision;
use App\Models\GrievanceDecisionApproval;
use App\Models\GrievanceLetter;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Process-focused grievance reporting (docs/grievance-management.md §12).
 *
 * Governance, not stigmatization: no report ranks or lists employees by the
 * grievances they filed. Grouped rows below the configured privacy threshold
 * are suppressed. Every report is limited to the viewer's organization scope.
 */
final class GrievanceReportService
{
    public const REPORTS = [
        'summary', 'by_organization', 'by_category', 'by_handler', 'by_status', 'overdue', 'escalations', 'appeals',
        'approvals', 'resolution_time', 'sla_compliance', 'correspondence', 'trend',
    ];

    public function __construct(
        private readonly GrievanceCaseAccessService $access,
        private readonly GrievanceSettings $settings,
        private readonly GrievancePresenter $presenter,
        private readonly GrievanceSlaService $sla,
    ) {}

    /**
     * @param  array{from?: string|null, to?: string|null, organization_id?: string|null, category_id?: string|null}  $filters
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, suppressed: int, totals?: array<string, mixed>}
     */
    public function run(string $report, User $user, array $filters): array
    {
        $cases = $this->scoped($user, $filters);

        return match ($report) {
            'by_organization' => $this->grouped($cases, 'organization_id', fn ($ids) => Organization::query()->whereIn('id', $ids)->get(['id', 'name_en', 'name_am'])->keyBy('id')),
            'by_category' => $this->grouped($cases, 'category_id', fn ($ids) => GrievanceCategory::query()->whereIn('id', $ids)->get(['id', 'name_en', 'name_am'])->keyBy('id')),
            'by_status' => $this->simpleGroup($cases, 'status'),
            'by_handler' => $this->byHandler($cases),
            'overdue' => $this->overdue($cases),
            'escalations' => $this->movements($cases, [GrievanceMovementType::TimeoutEscalation, GrievanceMovementType::ManualEscalation]),
            'appeals' => $this->movements($cases, [GrievanceMovementType::EmployeeAppeal]),
            'approvals' => $this->approvals($cases),
            'resolution_time' => $this->resolutionTime($cases),
            'sla_compliance' => $this->slaCompliance($cases),
            'correspondence' => $this->correspondence($cases),
            'trend' => $this->trend($cases),
            default => $this->summary($cases),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Grievance>
     */
    public function scoped(User $user, array $filters): Builder
    {
        $query = Grievance::query()->where('status', '!=', 'draft');
        if (! $user->isSuperAdmin()) {
            $this->access->applyPermissionScope($query, $user, 'grievance_reports.view');
        }
        if (! empty($filters['from'])) {
            $query->whereDate('submitted_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('submitted_at', '<=', $filters['to']);
        }
        foreach (['organization_id', 'category_id'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        return $query;
    }

    /** @return array{columns: list<string>, rows: list<array<string, mixed>>, suppressed: int, totals: array<string, mixed>} */
    private function summary(Builder $cases): array
    {
        $count = fn (?\Closure $f = null) => (clone $cases)->when($f, $f)->count();
        $totals = [
            'submitted' => $count(),
            'open' => $count(fn ($q) => $q->whereNotIn('status', ['closed', 'withdrawn', 'rejected_at_intake', 'decision_issued'])),
            'decision_issued' => $count(fn ($q) => $q->where('status', 'decision_issued')),
            'closed' => $count(fn ($q) => $q->where('status', 'closed')),
            'withdrawn' => $count(fn ($q) => $q->where('status', 'withdrawn')),
            'rejected_at_intake' => $count(fn ($q) => $q->where('status', 'rejected_at_intake')),
            'returned_for_correction' => $count(fn ($q) => $q->whereHas('amendments')->orWhere('status', 'returned_for_correction')),
            'auto_escalated' => $count(fn ($q) => $q->whereHas('stages', fn ($s) => $s->where('movement_type', 'timeout_escalation'))),
            'appealed' => $count(fn ($q) => $q->whereHas('appeals')),
            'overdue_now' => $count(fn ($q) => $q->whereHas('currentStage', fn ($s) => $s->open()->where('due_at', '<', now()))),
        ];

        return ['columns' => array_keys($totals), 'rows' => [$totals], 'suppressed' => 0, 'totals' => $totals];
    }

    /** @return array{columns: list<string>, rows: list<array<string, mixed>>, suppressed: int} */
    private function grouped(Builder $cases, string $column, \Closure $labels): array
    {
        $rows = (clone $cases)->selectRaw("{$column} as grp, count(*) as total")
            ->selectRaw("sum(case when status in ('closed','decision_issued') then 1 else 0 end) as resolved")
            ->selectRaw("sum(case when status not in ('closed','decision_issued','withdrawn','rejected_at_intake') then 1 else 0 end) as open")
            ->groupBy($column)->get();
        $names = $labels($rows->pluck('grp')->filter()->all());

        return $this->threshold($rows->map(fn ($r) => [
            'id' => $r->grp,
            'name_en' => $names[$r->grp]->name_en ?? '—',
            'name_am' => $names[$r->grp]->name_am ?? null,
            'total' => (int) $r->total,
            'resolved' => (int) $r->resolved,
            'open' => (int) $r->open,
        ]), ['name_en', 'total', 'resolved', 'open']);
    }

    /** @return array{columns: list<string>, rows: list<array<string, mixed>>, suppressed: int} */
    private function simpleGroup(Builder $cases, string $column): array
    {
        $rows = (clone $cases)->selectRaw("{$column} as grp, count(*) as total")->groupBy($column)->get()
            ->map(fn ($r) => ['key' => $r->grp, 'total' => (int) $r->total]);

        return ['columns' => ['key', 'total'], 'rows' => $rows->values()->all(), 'suppressed' => 0];
    }

    /** @return array{columns: list<string>, rows: list<array<string, mixed>>, suppressed: int} */
    private function byHandler(Builder $cases): array
    {
        $stages = GrievanceCaseStage::query()->whereIn('grievance_id', (clone $cases)->select('id'))
            ->selectRaw('handler_type, handler_id, count(*) as total')
            ->selectRaw("sum(case when status = 'resolved' then 1 else 0 end) as resolved")
            ->selectRaw("sum(case when status = 'escalated' then 1 else 0 end) as escalated_out")
            ->selectRaw('sum(case when is_current = '.$this->true().' and due_at is not null and due_at < ? then 1 else 0 end) as overdue', [now()])
            ->groupBy('handler_type', 'handler_id')->get();

        return $this->threshold($stages->map(fn ($s) => [
            ...$this->presenter->handler($this->enumValue($s->handler_type), $s->handler_id),
            'total' => (int) $s->total,
            'resolved' => (int) $s->resolved,
            'escalated_out' => (int) $s->escalated_out,
            'overdue' => (int) $s->overdue,
        ]), ['name_en', 'type', 'total', 'resolved', 'escalated_out', 'overdue']);
    }

    /** Overdue open stages: case numbers only (no names), for follow-up by authorized staff. */
    private function overdue(Builder $cases): array
    {
        $rows = GrievanceCaseStage::query()->with('grievance:id,reference_number,organization_id,category_id')
            ->whereIn('grievance_id', (clone $cases)->select('id'))->open()->where('due_at', '<', now())
            ->orderBy('due_at')->limit(500)->get()
            ->map(fn (GrievanceCaseStage $s) => [
                'reference_number' => $s->grievance?->reference_number,
                'handler' => $this->presenter->handler($s->handler_type->value, $s->handler_id)['name_en'],
                'stage_no' => $s->stage_no,
                'due_at' => $s->due_at?->toIso8601String(),
                'days_overdue' => abs((int) $this->sla->remainingDays($s)),
            ]);

        return ['columns' => ['reference_number', 'handler', 'stage_no', 'due_at', 'days_overdue'], 'rows' => $rows->values()->all(), 'suppressed' => 0];
    }

    /** @param  list<GrievanceMovementType>  $types */
    private function movements(Builder $cases, array $types): array
    {
        $rows = GrievanceCaseStage::query()->whereIn('grievance_id', (clone $cases)->select('id'))
            ->whereIn('movement_type', array_map(fn ($t) => $t->value, $types))
            ->selectRaw('movement_type, handler_type, handler_id, count(*) as total')
            ->groupBy('movement_type', 'handler_type', 'handler_id')->get()
            ->map(fn ($s) => ['movement_type' => $this->enumValue($s->movement_type), 'target' => $this->presenter->handler($this->enumValue($s->handler_type), $s->handler_id)['name_en'], 'total' => (int) $s->total]);

        return $this->threshold($rows, ['movement_type', 'target', 'total']);
    }

    private function approvals(Builder $cases): array
    {
        $decisions = GrievanceDecision::query()->whereIn('grievance_id', (clone $cases)->select('id'))->where('requires_executive_approval', true);
        $row = [
            'submitted' => (clone $decisions)->whereNotNull('submitted_for_approval_at')->count(),
            'pending' => (clone $decisions)->whereIn('status', ['pending_executive_approval', 'resubmitted'])->count(),
            'pending_overdue' => (clone $decisions)->whereIn('status', ['pending_executive_approval', 'resubmitted'])->where('approval_due_at', '<', now())->count(),
            'approved' => GrievanceDecisionApproval::query()->whereIn('decision_id', (clone $decisions)->select('id'))->where('action', 'approve')->count(),
            'returned' => GrievanceDecisionApproval::query()->whereIn('decision_id', (clone $decisions)->select('id'))->where('action', 'return_for_correction')->count(),
            'rejected' => GrievanceDecisionApproval::query()->whereIn('decision_id', (clone $decisions)->select('id'))->where('action', 'reject')->count(),
        ];

        return ['columns' => array_keys($row), 'rows' => [$row], 'suppressed' => 0];
    }

    /** Average/median days from submission to decision issued (calendar days). */
    private function resolutionTime(Builder $cases): array
    {
        $durations = (clone $cases)->whereNotNull('resolved_at')->whereNotNull('submitted_at')->get(['submitted_at', 'resolved_at', 'category_id'])
            ->map(fn ($g) => $g->submitted_at->diffInHours($g->resolved_at) / 24);
        $sorted = $durations->sort()->values();
        $median = $sorted->isEmpty() ? null : ($sorted->count() % 2 ? $sorted[intdiv($sorted->count(), 2)] : ($sorted[$sorted->count() / 2 - 1] + $sorted[$sorted->count() / 2]) / 2);
        $row = [
            'resolved_cases' => $durations->count(),
            'average_days' => $durations->isEmpty() ? null : round($durations->avg(), 1),
            'median_days' => $median === null ? null : round($median, 1),
            'max_days' => $durations->isEmpty() ? null : round($durations->max(), 1),
        ];

        return ['columns' => array_keys($row), 'rows' => [$row], 'suppressed' => 0];
    }

    /** Stages decided within their SLA vs late, per handler type. */
    private function slaCompliance(Builder $cases): array
    {
        $stages = GrievanceCaseStage::query()->whereIn('grievance_id', (clone $cases)->select('id'))->whereNotNull('due_at')->whereNotNull('completed_at')
            ->whereIn('status', ['resolved', 'escalated'])->get(['handler_type', 'due_at', 'completed_at', 'status']);
        $rows = $stages->groupBy(fn ($s) => $s->handler_type->value)->map(function (Collection $group, string $type) {
            $onTime = $group->filter(fn ($s) => $s->status->value === 'resolved' && $s->completed_at->lte($s->due_at))->count();

            return ['handler_type' => $type, 'stages' => $group->count(), 'on_time' => $onTime, 'late_or_escalated' => $group->count() - $onTime,
                'compliance_percent' => $group->count() ? round(100 * $onTime / $group->count(), 1) : null];
        })->values();

        return ['columns' => ['handler_type', 'stages', 'on_time', 'late_or_escalated', 'compliance_percent'], 'rows' => $rows->all(), 'suppressed' => 0];
    }

    private function correspondence(Builder $cases): array
    {
        $rows = GrievanceLetter::query()->whereIn('grievance_id', (clone $cases)->select('id'))
            ->selectRaw('letter_type, status, count(*) as total')->groupBy('letter_type', 'status')->get()
            ->map(fn ($l) => ['letter_type' => $l->letter_type instanceof \BackedEnum ? $l->letter_type->value : $l->letter_type, 'status' => $l->status instanceof \BackedEnum ? $l->status->value : $l->status, 'total' => (int) $l->total]);

        return ['columns' => ['letter_type', 'status', 'total'], 'rows' => $rows->values()->all(), 'suppressed' => 0];
    }

    /** Monthly counts per category (aggregate only). */
    private function trend(Builder $cases): array
    {
        $rows = (clone $cases)->whereNotNull('submitted_at')->with('category:id,name_en,name_am')->get(['id', 'submitted_at', 'category_id', 'systemic_issue_flag'])
            ->groupBy(fn ($g) => Carbon::parse($g->submitted_at)->format('Y-m').'|'.$g->category_id)
            ->map(fn (Collection $group, string $key) => [
                'month' => explode('|', $key)[0],
                'category' => $group->first()->category?->name_en,
                'category_am' => $group->first()->category?->name_am,
                'total' => $group->count(),
                'systemic_flagged' => $group->where('systemic_issue_flag', true)->count(),
            ])->sortBy('month')->values();

        return $this->threshold($rows, ['month', 'category', 'total', 'systemic_flagged']);
    }

    /**
     * Suppress small groups so individuals cannot be singled out.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $columns
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, suppressed: int}
     */
    private function threshold(Collection $rows, array $columns): array
    {
        $min = $this->settings->reportMinGroupSize();
        $kept = $rows->filter(fn ($r) => (int) ($r['total'] ?? 0) >= $min)->values();

        return ['columns' => $columns, 'rows' => $kept->all(), 'suppressed' => $rows->count() - $kept->count()];
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    private function true(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'true' : '1';
    }
}
