<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Exports\Assessment\AssessmentOversightExport;
use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\AssessmentInstitutionSubmission;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assessment\Oversight\AssessmentCoverageService;
use App\Services\Assessment\Oversight\AssessmentDataQualityService;
use App\Services\Assessment\Oversight\AssessmentOversightQueryService;
use App\Services\Assessment\Oversight\AssessmentResultDistributionService;
use App\Services\Assessment\Oversight\OversightAccess;
use App\Services\Assessment\Oversight\OversightScope;
use App\Services\Calendar\LocalizedDateService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Oversight exports (CSV, Excel, PDF). Same scope, filters and permissions
 * as the pages; every export is audited; no criterion responses or comments
 * are ever exported. Employee-level CSV streams in chunks; Excel/PDF of
 * employee lists are capped and point to CSV beyond the cap.
 */
class AssessmentOversightExportController extends Controller
{
    public const REPORTS = ['consolidated', 'unassessed', 'distribution', 'gender', 'data_quality', 'submissions'];

    /** Rows above which an employee-level Excel/PDF is refused in favour of the streamed CSV. */
    public const DOCUMENT_ROW_LIMIT = 5000;

    public function __construct(
        private readonly OversightAccess $access,
        private readonly AssessmentCoverageService $coverage,
        private readonly AssessmentResultDistributionService $distribution,
        private readonly AssessmentDataQualityService $quality,
        private readonly AssessmentOversightQueryService $query,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function export(Request $request, LocalizedDateService $dates): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('assessment_reports.export') && $this->access->canView($user), 403);
        $data = $request->validate([
            'report' => ['required', Rule::in(self::REPORTS)], 'format' => ['required', Rule::in(['csv', 'xlsx', 'pdf'])], 'cycle' => ['required', 'uuid'],
            'organization_id' => ['nullable', 'uuid'], 'organization_unit_id' => ['nullable', 'uuid'], 'form_version_id' => ['nullable', 'uuid'],
            'outcome' => ['nullable', Rule::in([...AssessmentCoverageService::OUTCOMES, 'unassessed'])], 'gender' => ['nullable', Rule::in(AssessmentCoverageService::GENDERS)],
            'reason_id' => ['nullable', 'uuid'], 'search' => ['nullable', 'string', 'max:100'],
        ]);
        $required = match ($data['report']) {
            'unassessed' => 'assessment_oversight.view_employee_status',
            'distribution' => 'assessment_oversight.view_results',
            'gender' => 'assessment_oversight.view_demographics',
            'data_quality' => 'assessment_oversight.view_data_quality',
            default => 'assessment_reports.view',
        };
        abort_unless($user->can($required), 403);
        abort_if(! empty($data['gender']) && ! $user->can('assessment_oversight.view_demographics'), 403);
        if (! empty($data['organization_id'])) {
            $this->access->authorizeOrganization($user, $data['organization_id'], $data['organization_unit_id'] ?? null);
        }
        $cycle = AssessmentCycle::query()->findOrFail($data['cycle']);
        $scope = $this->access->scopeFor($user);
        $filters = array_filter(array_intersect_key($data, array_flip(['organization_id', 'organization_unit_id', 'form_version_id', 'outcome', 'gender', 'reason_id', 'search'])));
        $title = __('assessments.oversight.reports.'.$data['report']);
        $filename = 'assessment-'.str_replace('_', '-', $data['report']).'-'.$cycle->code.'-'.now()->format('Ymd-His');

        if ($data['report'] === 'unassessed') {
            $filters['outcome'] ??= 'unassessed';
            $source = $this->query->employeeQuery($cycle, $scope, $filters)->orderBy('t.employee_number')->orderBy('t.employee_id');
            $headings = $this->headings(['employee_number', 'employee_name', ...($user->can('assessment_oversight.view_demographics') ? ['gender'] : []), 'organization', 'unit', 'position', 'form', 'outcome', 'reason']);
            $map = fn ($row): array => $this->employeeCells($this->query->employeeRow($row, false), $user);
            $count = (clone $source)->count();
            $this->audited($user, $cycle, $data, $filters, $count);
            if ($data['format'] === 'csv') {
                return $this->streamCsv($filename, $headings, $source, $map);
            }
            if ($count > self::DOCUMENT_ROW_LIMIT) {
                return back()->withErrors(['export' => __('assessments.oversight.export_too_large', ['limit' => self::DOCUMENT_ROW_LIMIT])]);
            }
            $rows = $source->get()->map($map)->all();
        } else {
            [$headings, $rows] = $this->aggregateReport($data['report'], $cycle, $scope, $filters, $user);
            $this->audited($user, $cycle, $data, $filters, count($rows));
        }

        $rows = array_map(fn (array $row): array => array_map(fn ($v) => $this->safe($v), $row), $rows);

        return match ($data['format']) {
            'csv' => $this->streamCsv($filename, $headings, collect($rows), fn (array $r): array => $r),
            'xlsx' => Excel::download(new AssessmentOversightExport($headings, $rows), $filename.'.xlsx'),
            'pdf' => Pdf::loadView('assessments.oversight-report-pdf', [
                'title' => $title, 'headings' => $headings, 'rows' => $rows,
                'cycle' => ($this->label($cycle->only(['name_en', 'name_am']))).' ('.$dates->displayDate($cycle->period_start).' – '.$dates->displayDate($cycle->period_end).')',
                'generated' => __('assessments.oversight.generated', ['at' => $dates->displayDateTime(now()), 'user' => $user->name]),
                'note' => __('assessments.oversight.export_note'),
            ])->setPaper('a4', 'landscape')->download($filename.'.pdf'),
        };
    }

    /** @return array{0: list<string>, 1: list<list<scalar|null>>} */
    private function aggregateReport(string $report, AssessmentCycle $cycle, OversightScope $scope, array $filters, User $user): array
    {
        $demographics = $user->can('assessment_oversight.view_demographics');
        $suppress = fn (?int $n): ?int => $n !== null && $cycle->small_group_threshold !== null && $n > 0 && $n < $cycle->small_group_threshold ? null : $n;
        $orgs = fn (array $ids): Collection => Organization::query()->whereIn('id', array_filter($ids))->get(['id', 'code', 'name_en', 'name_am'])->keyBy('id');

        if ($report === 'consolidated') {
            $metrics = $this->coverage->institutionCoverage($cycle, $scope, $filters);
            $dist = $user->can('assessment_oversight.view_results') ? $this->distribution->distribution($cycle, $scope, $filters, 'organization_id') : null;
            $names = $orgs(array_keys($metrics));
            // "Reason for unassessed" column of the source-style report, from the records themselves.
            $reasons = $this->coverage->source($cycle, $scope, $filters)->whereIn('t.outcome', ['approved_exception', 'reported_unassessed'])
                ->leftJoin('assessment_unassessed_reasons as er', 'er.id', '=', 't.reason_id')
                ->leftJoin('assessment_unassessed_reasons as rr', 'rr.code', '=', 't.unassessed_reason')
                ->selectRaw('t.organization_id, COALESCE(er.code, rr.code, t.unassessed_reason) as code, COALESCE(er.name_en, rr.name_en) as name_en, COALESCE(er.name_am, rr.name_am) as name_am, COUNT(*) as n')
                ->groupBy('t.organization_id', 'er.code', 'rr.code', 't.unassessed_reason', 'er.name_en', 'rr.name_en', 'er.name_am', 'rr.name_am')->get()->groupBy('organization_id');
            $bandCodes = collect($dist['bands'] ?? [])->map(fn (array $b): array => [$b['code'], $this->label(['name_en' => $b['label_en'], 'name_am' => $b['label_am']])]);
            $headings = [...$this->headings(['organization_code', 'organization', 'eligible', 'assessed', 'unassessed', 'coverage', 'approved_exception', 'reported_unassessed', 'reasons', 'excluded']),
                ...($demographics ? $this->headings(['male_eligible', 'female_eligible', 'unknown_eligible', 'male_assessed', 'female_assessed', 'unknown_assessed']) : []),
                ...$bandCodes->pluck(1)->all()];
            $rows = [];
            foreach ($metrics as $orgId => $m) {
                $g = $m['gender'];
                $rows[] = [
                    $names[$orgId]->code ?? '', $this->label($names[$orgId] ?? null), $m['eligible'], $m['assessed'], $m['unassessed'], $m['coverage_percent'],
                    $m['outcomes']['approved_exception'], $m['outcomes']['reported_unassessed'],
                    collect($reasons[$orgId] ?? [])->map(fn ($r): string => ($this->label((array) $r) ?: $r->code).': '.$r->n)->implode('; '), $m['excluded'],
                    ...($demographics ? [$suppress($g['male']['eligible']), $suppress($g['female']['eligible']), $suppress($g['unknown']['eligible'] + $g['other']['eligible']),
                        $suppress($g['male']['assessed']), $suppress($g['female']['assessed']), $suppress($g['unknown']['assessed'] + $g['other']['assessed'])] : []),
                    ...$bandCodes->map(fn (array $b): int => (int) ($dist['breakdown'][$orgId][$b[0]] ?? 0))->all(),
                ];
            }
            usort($rows, fn ($a, $b) => strcmp((string) $a[1], (string) $b[1]));

            return [$headings, $rows];
        }

        if ($report === 'distribution') {
            $dist = $this->distribution->distribution($cycle, $scope, $filters);

            return [$this->headings(['band', 'range', 'count', 'percent']), array_map(fn (array $b): array => [
                $this->label(['name_en' => $b['label_en'], 'name_am' => $b['label_am']]),
                ($b['min_inclusive'] ? '[' : '(').$b['min_score'].' – '.$b['max_score'].($b['max_inclusive'] ? ']' : ')'), $b['count'], $b['percent'],
            ], $dist['bands'])];
        }

        if ($report === 'gender') {
            $metrics = $this->coverage->institutionCoverage($cycle, $scope, $filters);
            $names = $orgs(array_keys($metrics));
            $rows = [];
            foreach ($metrics as $orgId => $m) {
                foreach (AssessmentCoverageService::GENDERS as $gender) {
                    $cell = $m['gender'][$gender];
                    $hidden = $suppress($cell['eligible']) === null && $cell['eligible'] > 0;
                    $rows[] = [$this->label($names[$orgId] ?? null), __('assessments.oversight.genders.'.$gender), $hidden ? null : $cell['eligible'], $hidden ? null : $cell['assessed'], $hidden ? null : $cell['unassessed'], $hidden ? null : $cell['coverage_percent']];
                }
            }

            return [$this->headings(['organization', 'gender', 'eligible', 'assessed', 'unassessed', 'coverage']), $rows];
        }

        if ($report === 'data_quality') {
            $summary = $this->quality->summary($cycle, $scope, $filters['organization_id'] ?? null);
            $rows = [];
            foreach ($summary['rules'] as $code => $rule) {
                if ($rule['count'] > 0) {
                    $rows[] = [$code, __('assessments.oversight.severity.'.$rule['severity']), $rule['count']];
                }
            }
            foreach ($summary['cycle'] as $issue) {
                $rows[] = [$issue['code'], __('assessments.oversight.severity.'.$issue['severity']), 1];
            }

            return [$this->headings(['issue', 'severity', 'count']), $rows];
        }

        // submissions
        $query = AssessmentInstitutionSubmission::query()->with('organization:id,code,name_en,name_am')->where('assessment_cycle_id', $cycle->id);
        $scope->apply($query->getQuery(), 'organization_id');

        return [$this->headings(['organization', 'revision', 'status', 'eligible', 'assessed', 'unassessed', 'coverage', 'submitted_at', 'verified_at', 'finalized_at']),
            $query->when($filters['organization_id'] ?? null, fn ($q, $v) => $q->where('organization_id', $v))->orderBy('organization_id')->orderBy('revision_no')->limit(5000)->get()
                ->map(fn (AssessmentInstitutionSubmission $s): array => [$this->label($s->organization), $s->revision_no, __('assessments.oversight.submission_statuses.'.$s->status),
                    $s->eligible_count, $s->assessed_count, $s->unassessed_count, $s->coverage_percent,
                    $s->submitted_at?->toDateTimeString(), $s->verified_at?->toDateTimeString(), $s->finalized_at?->toDateTimeString()])->all()];
    }

    /** @return list<scalar|null> */
    private function employeeCells(array $row, User $user): array
    {
        return array_map(fn ($v) => $this->safe($v), [
            $row['employee_number'], (app()->getLocale() === 'am' ? $row['name'] : ($row['name_en'] ?: $row['name'])) ?? '',
            ...($user->can('assessment_oversight.view_demographics') ? [__('assessments.oversight.genders.'.$row['gender'])] : []),
            $this->label($row['organization']), $this->label($row['unit']), $this->label(['name_en' => $row['position']['title_en'], 'name_am' => $row['position']['title_am']]),
            $row['form'] ? $this->label($row['form']).' v'.$row['form']['version_no'] : '',
            __('assessments.oversight.outcomes.'.$row['outcome']),
            $row['reason'] ? $this->label($row['reason']) : '',
        ]);
    }

    private function streamCsv(string $filename, array $headings, iterable|Builder $source, \Closure $map): Response
    {
        return response()->streamDownload(function () use ($headings, $source, $map): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Amharic
            fputcsv($out, $headings);
            $write = function ($row) use ($out, $map): void {
                fputcsv($out, array_map(fn ($v) => $this->safe($v), $map($row)));
            };
            if ($source instanceof Builder) {
                $source->chunk(1000, fn ($rows) => $rows->each($write));
            } else {
                foreach ($source as $row) {
                    $write($row);
                }
            }
            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function audited(User $user, AssessmentCycle $cycle, array $data, array $filters, int $rows): void
    {
        $this->audit->execute(AuditEventType::AssessmentOversightExported, $user, $cycle, $filters['organization_id'] ?? null,
            newValues: ['report' => $data['report'], 'format' => $data['format'], 'rows' => $rows, 'filters' => $filters], request: request());
    }

    /** @param list<string> $columns */
    private function headings(array $columns): array
    {
        return array_map(fn (string $c): string => __('assessments.oversight.columns.'.$c), $columns);
    }

    private function label(mixed $value): string
    {
        $value = is_object($value) && method_exists($value, 'toArray') ? $value->toArray() : (array) $value;

        return (string) ((app()->getLocale() === 'am' && ! empty($value['name_am'])) ? $value['name_am'] : ($value['name_en'] ?? $value['name_am'] ?? ''));
    }

    /** CSV/formula-injection guard: a leading = + - @ (or tab/CR) is neutralized. */
    private function safe(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
