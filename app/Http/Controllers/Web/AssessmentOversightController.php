<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\AssessmentExclusionRequest;
use App\Models\AssessmentFormVersion;
use App\Models\AssessmentInstitutionSubmission;
use App\Models\AssessmentUnassessedReason;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\Assessment\Oversight\AssessmentCoverageService;
use App\Services\Assessment\Oversight\AssessmentDashboardService;
use App\Services\Assessment\Oversight\AssessmentDataQualityService;
use App\Services\Assessment\Oversight\AssessmentInstitutionSubmissionService;
use App\Services\Assessment\Oversight\AssessmentOversightQueryService;
use App\Services\Assessment\Oversight\AssessmentResultDistributionService;
use App\Services\Assessment\Oversight\OversightAccess;
use App\Services\Assessment\Oversight\OversightScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Assessment Oversight & Compliance pages (docs/assessment-oversight.md).
 * Read-only monitoring; every number comes from the oversight services and
 * every page re-checks permission and organization/unit scope.
 */
class AssessmentOversightController extends Controller
{
    public function __construct(
        private readonly OversightAccess $access,
        private readonly AssessmentCoverageService $coverage,
        private readonly AssessmentResultDistributionService $distribution,
        private readonly AssessmentDataQualityService $quality,
        private readonly AssessmentOversightQueryService $query,
        private readonly AssessmentInstitutionSubmissionService $submissions,
        private readonly AssessmentDashboardService $dashboardService,
    ) {}

    public function dashboard(Request $request): Response
    {
        $user = $this->viewer($request);
        abort_unless($user->canAny(['assessment_oversight.view_dashboard', 'assessment_oversight.view_unit']), 403);
        [$cycle, $scope] = $this->context($request, $user);
        $filters = $request->validate(['organization_id' => ['nullable', 'uuid'], 'organization_unit_id' => ['nullable', 'uuid']]);
        $organizations = $cycle ? $this->organizationOptions($cycle, $scope) : [];
        if (! empty($filters['organization_unit_id'])) {
            $unit = OrganizationUnit::query()->findOrFail($filters['organization_unit_id']);
            abort_if(! empty($filters['organization_id']) && $filters['organization_id'] !== $unit->organization_id, 422);
            $filters['organization_id'] = $unit->organization_id;
            $this->access->authorizeOrganization($user, $unit->organization_id, $unit->id);
            $scope = new OversightScope([$unit->organization_id], [$unit->id]);
        } elseif (! empty($filters['organization_id'])) {
            $this->access->authorizeOrganization($user, $filters['organization_id']);
            $scope = new OversightScope([$filters['organization_id']], $scope->unitIds);
        }
        $data = null;
        if ($cycle) {
            $totals = $this->coverage->totals($cycle, $scope);
            $dq = $this->quality->summary($cycle, $scope);
            $distribution = $user->can('assessment_oversight.view_results') ? $this->distribution->distribution($cycle, $scope) : null;
            $details = $this->dashboardService->getOperationalDetails($cycle, $scope);
            $institutionCounts = $this->query->institutionStatusCounts($cycle, $scope);
            $byInstitution = $this->query->institutions($cycle, $scope, ['sort' => 'coverage', 'direction' => 'asc'], 15);
            $byInstitution->through(function (array $row) use ($user, $cycle): array {
                if (! $user->can('assessment_oversight.view_demographics') || $cycle->small_group_threshold !== null) {
                    $row['male_assessed'] = null;
                    $row['female_assessed'] = null;
                }
                if (! $user->can('assessment_oversight.view_data_quality')) {
                    $row['issues'] = null;
                }

                return $row;
            });
            $data = [
                'totals' => $this->demographics($user, $cycle, $totals),
                'institutions' => $institutionCounts,
                'actions' => $user->can('assessment_oversight.view_data_quality') ? $this->dashboardService->getActionRequired($dq, []) : [],
                'unavailable' => array_keys(array_filter($details, fn (array $section): bool => ! $section['available'])),
                'issues' => $user->can('assessment_oversight.view_data_quality') ? $this->issueTotals($dq) : null,
                'distribution' => $distribution,
                'comparison' => $byInstitution->items(),
                'institutionPage' => $byInstitution,
                'forms' => $details['forms']['data'] ?? [],
                'peers' => $details['evaluators']['data'] ?? [],
                'reasons' => $details['reasons']['data'] ?? ['exceptions' => [], 'reported' => [], 'excluded' => []],
                'units' => $scope->organizationIds !== null && count($scope->organizationIds) === 1
                    ? collect($this->query->units($cycle, $scope, $scope->organizationIds[0]))->map(fn (array $m): array => $this->demographics($user, $cycle, $m))->all() : [],
                'trend' => $this->query->trend($cycle, $scope),
                'reconciliation' => AssessmentCoverageService::reconcile($totals, $distribution && $distribution['policy'] ? $distribution['classified'] : null,
                    $distribution && $distribution['policy'] ? $distribution['assessed'] - $dq['rules']['UNCLASSIFIED_RESULT']['count'] : null),
            ];
        }

        return $this->render('Dashboard', $request, $user, $cycle, ['data' => $data, 'filters' => $filters, 'organizations' => $organizations]);
    }

    public function institutions(Request $request): Response
    {
        $user = $this->viewer($request, 'assessment_oversight.view_institutions');
        [$cycle, $scope] = $this->context($request, $user);
        $filters = $request->validate([
            'organization_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'], 'submission_status' => ['nullable', 'string', 'max:20'],
            'participation' => ['nullable', Rule::in(['included', 'excluded', 'all'])],
            'sort' => ['nullable', Rule::in(AssessmentOversightQueryService::INSTITUTION_SORTS)], 'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        if (! empty($filters['organization_id'])) {
            $this->access->authorizeOrganization($user, $filters['organization_id']);
        }
        $rows = $cycle ? $this->query->institutions($cycle, $scope, $filters) : null;
        if ($rows && ! $user->can('assessment_oversight.view_demographics')) {
            $rows->through(fn (array $row): array => [...$row, 'male_assessed' => null, 'female_assessed' => null]);
        }

        return $this->render('Institutions', $request, $user, $cycle, ['rows' => $rows, 'filters' => $filters]);
    }

    public function institution(Request $request, Organization $organization): Response
    {
        $user = $this->viewer($request);
        [$cycle, $scope] = $this->context($request, $user);
        $this->access->authorizeOrganization($user, $organization->id);
        abort_if($cycle === null, 404);
        $filters = ['organization_id' => $organization->id];
        $totals = $this->coverage->totals($cycle, $scope, $filters);
        $participation = $cycle->organizations()->where('organization_id', $organization->id)->first();
        abort_if($participation === null, 404);
        // Detect drift first, so the history below already shows an OUTDATED mark.
        $current = $this->submissions->latest($cycle, $organization->id);
        $outdated = $current ? $this->submissions->detectOutdatedSubmission($current, $user) : null;
        $history = AssessmentInstitutionSubmission::query()->with(['events.actor:id,name', 'submitter:id,name'])
            ->where('assessment_cycle_id', $cycle->id)->where('organization_id', $organization->id)->orderByDesc('revision_no')->get();
        $latest = $history->first();
        $readiness = $user->can('assessment_submissions.submit') ? $this->submissions->validateReadiness($cycle, $organization->id) : null;

        return $this->render('Institution', $request, $user, $cycle, [
            'organization' => $organization->only(['id', 'code', 'name_en', 'name_am']),
            'participation' => $participation->only(['status', 'submission_status', 'included_at', 'excluded_at', 'exclusion_reason']),
            'totals' => $this->demographics($user, $cycle, $totals),
            'units' => collect($this->query->units($cycle, $scope, $organization->id))->map(fn (array $m): array => $this->demographics($user, $cycle, $m))->all(),
            'forms' => $this->query->formUsage($cycle, $scope, $filters),
            'peers' => $this->query->peerCompletion($cycle, $scope, $filters),
            'distribution' => $user->can('assessment_oversight.view_results') ? $this->distribution->distribution($cycle, $scope, $filters) : null,
            'reasons' => $this->query->reasons($cycle, $scope, $filters),
            'issues' => $user->can('assessment_oversight.view_data_quality') ? $this->issueTotals($this->quality->summary($cycle, $scope, $organization->id)) : null,
            'history' => $history->map(fn (AssessmentInstitutionSubmission $s): array => [
                ...$s->only(['id', 'revision_no', 'status', 'eligible_count', 'assessed_count', 'unassessed_count', 'coverage_percent', 'return_reason', 'sign_off_note']),
                'submitted_at' => $s->submitted_at?->toIso8601String(), 'verified_at' => $s->verified_at?->toIso8601String(), 'finalized_at' => $s->finalized_at?->toIso8601String(),
                'submitter' => $s->submitter?->name,
                'events' => $s->events->map(fn ($e): array => ['action' => $e->action, 'actor' => $e->actor?->name, 'comment' => $e->comment, 'at' => $e->created_at?->toIso8601String()])->all(),
            ])->all(),
            'changedAfterFinalization' => $latest?->status === 'finalized' && ($outdated['changed'] ?? false),
            'readiness' => $readiness,
            'exclusions' => $this->exclusions($user, $cycle, $organization->id),
            'exceptionReasons' => $user->can('assessment_exclusions.request') ? $this->exceptionReasons() : [],
            'actions' => $this->submissionActions($user, $latest, $organization->id) + [
                'submit' => $readiness !== null && $readiness['ready'] && $this->access->canSeeOrganization($user, $organization->id),
                'requestExclusion' => $user->can('assessment_exclusions.request') && $cycle->isEligibilityFinalized(),
            ],
            'latest' => $latest?->only(['id', 'status', 'revision_no']),
        ]);
    }

    /** Coverage and unassessed lists: one employee per row, paginated. */
    public function employees(Request $request): Response
    {
        $user = $this->viewer($request, 'assessment_oversight.view_employee_status');
        [$cycle, $scope] = $this->context($request, $user);
        $filters = $this->employeeFilters($request, $user);
        $rows = $cycle ? $this->query->employees($cycle, $scope, $filters, $user->can('assessment_oversight.view_results')) : null;

        return $this->render('Employees', $request, $user, $cycle, [
            'rows' => $rows, 'filters' => $filters, 'reasons' => AssessmentUnassessedReason::query()->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_am']),
            'units' => $cycle && ! empty($filters['organization_id']) ? $this->unitOptions($filters['organization_id'], $scope) : [],
            'organizations' => $cycle && ! $scope->isEmpty() ? $this->organizationOptions($cycle, $scope) : [],
            'canRequestExclusion' => $cycle?->isEligibilityFinalized() && $user->can('assessment_exclusions.request'),
            'exceptionReasons' => $user->can('assessment_exclusions.request') ? $this->exceptionReasons() : [],
        ]);
    }

    public function distribution(Request $request): Response
    {
        $user = $this->viewer($request, 'assessment_oversight.view_results');
        [$cycle, $scope] = $this->context($request, $user);
        $filters = $this->aggregateFilters($request, $user);
        $breakdown = $request->validate(['breakdown' => ['nullable', Rule::in(['organization_id', 'organization_unit_id', 'gender', 'form_version_id'])]])['breakdown'] ?? null;
        abort_if($breakdown === 'gender' && ! $user->can('assessment_oversight.view_demographics'), 403);
        $data = $cycle ? $this->distribution->distribution($cycle, $scope, $filters, $breakdown) : null;

        return $this->render('Distribution', $request, $user, $cycle, [
            'data' => $data, 'filters' => $filters + ['breakdown' => $breakdown],
            'labels' => $data && $breakdown ? $this->groupLabels($breakdown, array_keys($data['breakdown'])) : [],
        ]);
    }

    public function gender(Request $request): Response
    {
        $user = $this->viewer($request, 'assessment_oversight.view_demographics');
        [$cycle, $scope] = $this->context($request, $user);
        $filters = $this->aggregateFilters($request, $user);
        $totals = $cycle ? $this->coverage->totals($cycle, $scope, $filters) : null;
        $byInstitution = $cycle ? collect($this->coverage->institutionCoverage($cycle, $scope, $filters))->map(fn (array $m): array => $this->demographics($user, $cycle, $m))->values()->all() : [];

        return $this->render('Gender', $request, $user, $cycle, [
            'totals' => $totals ? $this->demographics($user, $cycle, $totals) : null, 'institutions' => $byInstitution, 'filters' => $filters,
            'labels' => $this->groupLabels('organization_id', array_column($byInstitution, 'group_key')),
        ]);
    }

    public function dataQuality(Request $request): Response
    {
        $user = $this->viewer($request, 'assessment_oversight.view_data_quality');
        [$cycle, $scope] = $this->context($request, $user);
        $filters = $request->validate(['code' => ['nullable', Rule::in(array_keys(AssessmentDataQualityService::RULES))], 'organization_id' => ['nullable', 'uuid'], 'organization_unit_id' => ['nullable', 'uuid'], 'severity' => ['nullable', Rule::in(['info', 'warning', 'blocking'])]]);
        if (! empty($filters['organization_unit_id'])) {
            $unit = OrganizationUnit::query()->findOrFail($filters['organization_unit_id']);
            abort_if(! empty($filters['organization_id']) && $filters['organization_id'] !== $unit->organization_id, 422);
            $this->access->authorizeOrganization($user, $unit->organization_id, $unit->id);
            $filters['organization_id'] = $unit->organization_id;
            $scope = new OversightScope([$unit->organization_id], [$unit->id]);
        }
        if (! empty($filters['organization_id'])) {
            $this->access->authorizeOrganization($user, $filters['organization_id']);
        }
        $summary = $cycle ? $this->quality->summary($cycle, $scope, $filters['organization_id'] ?? null) : null;

        return $this->render('DataQuality', $request, $user, $cycle, [
            'summary' => $summary, 'filters' => $filters,
            'issues' => $cycle && ! empty($filters['code']) ? $this->quality->issues($cycle, $scope, $filters['code'], $filters['organization_id'] ?? null) : null,
            'labels' => $summary ? $this->groupLabels('organization_id', array_keys($summary['organizations'])) : [],
        ]);
    }

    public function submissionsIndex(Request $request): Response
    {
        $user = $this->viewer($request);
        abort_unless($user->canAny(['assessment_submissions.submit', 'assessment_submissions.review', 'assessment_submissions.verify', 'assessment_submissions.finalize']), 403);
        [$cycle, $scope] = $this->context($request, $user);
        $filters = $request->validate(['organization_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(['submitted', 'under_city_review', 'returned', 'verified', 'finalized', 'rejected', 'outdated'])]]);
        if (! empty($filters['organization_id'])) {
            $this->access->authorizeOrganization($user, $filters['organization_id']);
        }
        $query = AssessmentInstitutionSubmission::query()->with(['organization:id,code,name_en,name_am', 'submitter:id,name'])->where('assessment_cycle_id', $cycle?->id);
        $scope->apply($query->getQuery(), 'organization_id');
        $page = $cycle === null ? null : $query
            ->when($filters['organization_id'] ?? null, fn ($q, $v) => $q->where('organization_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('submitted_at')->paginate(25)->withQueryString()
            ->through(fn (AssessmentInstitutionSubmission $s): array => [
                ...$s->only(['id', 'revision_no', 'status', 'eligible_count', 'assessed_count', 'unassessed_count', 'coverage_percent', 'return_reason']),
                'organization' => $s->organization?->only(['id', 'code', 'name_en', 'name_am']), 'submitter' => $s->submitter?->name,
                'submitted_at' => $s->submitted_at?->toIso8601String(), 'verified_at' => $s->verified_at?->toIso8601String(), 'finalized_at' => $s->finalized_at?->toIso8601String(),
                'actions' => $this->submissionActions($user, $s, $s->organization_id),
            ]);

        return $this->render('Submissions', $request, $user, $cycle, ['rows' => $page, 'filters' => $filters]);
    }

    public function reports(Request $request): Response
    {
        $user = $this->viewer($request, 'assessment_reports.view');
        [$cycle, $scope] = $this->context($request, $user);
        $filters = $this->aggregateFilters($request, $user);
        $rows = [];
        $bands = [];
        $summary = null;
        if ($cycle) {
            $metrics = $this->coverage->institutionCoverage($cycle, $scope, $filters);
            $distribution = $user->can('assessment_oversight.view_results') ? $this->distribution->distribution($cycle, $scope, $filters, 'organization_id') : null;
            $bands = $distribution['bands'] ?? [];
            $reasons = $this->query->reasons($cycle, $scope, $filters);
            $labels = $this->groupLabels('organization_id', array_keys($metrics));
            foreach ($metrics as $orgId => $m) {
                $m = $this->demographics($user, $cycle, $m);
                $rows[] = ['organization' => $labels[$orgId] ?? ['id' => $orgId], 'metrics' => $m, 'bands' => $distribution['breakdown'][$orgId] ?? null];
            }
            usort($rows, fn ($a, $b) => strcmp((string) ($a['organization']['name_en'] ?? ''), (string) ($b['organization']['name_en'] ?? '')));
            $summary = [
                // These are report navigation metrics, not a second dashboard.
                // They deliberately reuse the same scoped aggregate definitions as
                // the detailed reports and exports below.
                'institutions' => $this->query->institutionStatusCounts($cycle, $scope, $filters),
                'quality' => $user->can('assessment_oversight.view_data_quality')
                    ? $this->issueTotals($this->quality->summary($cycle, $scope, $filters['organization_id'] ?? null))
                    : null,
            ];
        }

        return $this->render('Reports', $request, $user, $cycle, [
            'rows' => $rows, 'bands' => $bands, 'filters' => $filters,
            'reasons' => $reasons ?? null, 'totals' => $cycle ? $this->demographics($user, $cycle, $this->coverage->totals($cycle, $scope, $filters)) : null,
            'summary' => $summary,
            'organizations' => $cycle ? $this->organizationOptions($cycle, $scope) : [],
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function viewer(Request $request, ?string $permission = null): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->canView($user) && ($permission === null || $user->can($permission)), 403);

        return $user;
    }

    /** @return array{0: ?AssessmentCycle, 1: OversightScope} */
    private function context(Request $request, User $user): array
    {
        $request->validate(['cycle' => ['nullable', 'uuid']]);

        return [$this->query->selectCycle($request->query('cycle')), $this->access->scopeFor($user)];
    }

    /** @param array<string, mixed> $props */
    private function render(string $page, Request $request, User $user, ?AssessmentCycle $cycle, array $props): Response
    {
        return Inertia::render('Assessments/Oversight/'.$page, [
            ...$props,
            'cycle' => $cycle ? [
                ...$cycle->only(['id', 'code', 'name_en', 'name_am', 'status', 'eligibility_status', 'population_rule', 'min_service_days', 'exclusion_reduces_denominator', 'small_group_threshold', 'reminder_days_before']),
                'period_start' => $cycle->period_start?->toDateString(), 'period_end' => $cycle->period_end?->toDateString(), 'reference_date' => $cycle->reference_date?->toDateString(),
                'submission_deadline' => $cycle->submission_deadline?->toDateString(), 'verification_deadline' => $cycle->verification_deadline?->toDateString(),
                'eligibility_finalized_at' => $cycle->eligibility_finalized_at?->toIso8601String(),
                'needs_decision' => $this->needsDecision($cycle),
            ] : null,
            'cycles' => $this->query->cycleOptions(),
            'scope' => ['cityWide' => $this->access->scopeFor($user)->isCityWide(), 'unitLimited' => $this->access->scopeFor($user)->unitIds !== null],
            'can' => [
                'dashboard' => $user->can('assessment_oversight.view_dashboard'),
                'institutions' => $user->can('assessment_oversight.view_institutions'),
                'employees' => $user->can('assessment_oversight.view_employee_status'),
                'results' => $user->can('assessment_oversight.view_results'),
                'demographics' => $user->can('assessment_oversight.view_demographics'),
                'dataQuality' => $user->can('assessment_oversight.view_data_quality'),
                'submissions' => $user->canAny(['assessment_submissions.submit', 'assessment_submissions.review', 'assessment_submissions.verify', 'assessment_submissions.finalize']),
                'reports' => $user->can('assessment_reports.view'),
                'export' => $user->can('assessment_reports.export'),
                'setup' => $user->canAny(['assessment_oversight.manage_cycles', 'assessment_oversight.manage_policies']),
            ],
        ]);
    }

    /** Policy questions this cycle has not answered; shown, never guessed. */
    private function needsDecision(AssessmentCycle $cycle): array
    {
        return array_keys(array_filter([
            'eligible_statuses' => $cycle->eligible_employee_statuses === null,
            'new_hires' => $cycle->min_service_days === null,
            'exclusions_reduce_denominator' => $cycle->exclusion_reduces_denominator === null,
            'small_group_threshold' => $cycle->small_group_threshold === null,
            'deadlines' => $cycle->submission_deadline === null,
            'reminders' => $cycle->reminder_days_before === null,
            'result_bands' => $cycle->result_band_policy_id === null,
        ]));
    }

    /**
     * Gender figures only for users allowed demographic analytics, and
     * suppressed in groups smaller than the cycle's configured threshold.
     */
    private function demographics(User $user, AssessmentCycle $cycle, array $metrics): array
    {
        if (! $user->can('assessment_oversight.view_demographics')) {
            $metrics['gender'] = null;

            return $metrics;
        }
        $threshold = $cycle->small_group_threshold;
        if ($threshold !== null) {
            foreach ($metrics['gender'] as $g => $cell) {
                if ($cell['eligible'] > 0 && $cell['eligible'] < $threshold) {
                    $metrics['gender'][$g] = ['eligible' => null, 'assessed' => null, 'unassessed' => null, 'coverage_percent' => null, 'suppressed' => true];
                }
            }
        }

        return $metrics;
    }

    private function issueTotals(array $summary): array
    {
        $by = fn (string $severity): int => (int) collect($summary['rules'])->where('severity', $severity)->sum('count');

        return [
            'blocking' => $by('blocking'), 'warning' => $by('warning'), 'info' => $by('info'),
            'missing_evaluator' => $summary['rules']['MISSING_EVALUATOR']['count'],
            'no_applicable_form' => $summary['rules']['NO_APPLICABLE_FORM']['count'],
            'assignment_errors' => $summary['rules']['MISSING_ASSESSMENT_ASSIGNMENT']['count'] + $summary['rules']['MULTIPLE_APPLICABLE_FORMS']['count'] + $summary['rules']['FORM_ASSIGNMENT_MISMATCH']['count'],
            'evaluator' => collect(AssessmentDataQualityService::EVALUATOR_RULES)->mapWithKeys(fn (string $code): array => [$code => $summary['rules'][$code]['count']])->all(),
            'rules' => $summary['rules'], 'cycle' => $summary['cycle'],
        ];
    }

    private function submissionActions(User $user, ?AssessmentInstitutionSubmission $s, string $organizationId): array
    {
        $inScope = $this->access->canSeeOrganization($user, $organizationId);
        $status = $s?->status;
        $notMine = $s !== null && $s->submitted_by !== $user->id;
        $notVerifier = $s !== null && $s->verified_by !== $user->id;

        return [
            'startReview' => $inScope && $notMine && $user->can('assessment_submissions.review') && $status === 'submitted',
            'return' => $inScope && $user->can('assessment_submissions.return') && in_array($status, ['submitted', 'under_city_review', 'verified', 'outdated'], true),
            'reject' => $inScope && $user->can('assessment_submissions.return') && $status === 'submitted',
            'verify' => $inScope && $notMine && $user->can('assessment_submissions.verify') && in_array($status, ['submitted', 'under_city_review'], true),
            'finalize' => $inScope && $notMine && $notVerifier && $user->can('assessment_submissions.finalize') && $status === 'verified',
        ];
    }

    private function exclusions(User $user, AssessmentCycle $cycle, string $organizationId): array
    {
        if (! $user->canAny(['assessment_exclusions.request', 'assessment_exclusions.approve'])) {
            return [];
        }

        return AssessmentExclusionRequest::query()->with(['employee:id,employee_number,full_name,name_en', 'reason:id,code,name_en,name_am', 'requester:id,name', 'decider:id,name'])
            ->where('assessment_cycle_id', $cycle->id)->where('organization_id', $organizationId)->latest('requested_at')->limit(200)->get()
            ->map(fn (AssessmentExclusionRequest $x): array => [
                ...$x->only(['id', 'status', 'note', 'decision_note', 'eligibility_id']),
                'employee' => $x->employee?->only(['id', 'employee_number', 'full_name', 'name_en']), 'reason' => $x->reason?->only(['code', 'name_en', 'name_am']),
                'requester' => $x->requester?->name, 'decider' => $x->decider?->name,
                'requested_at' => $x->requested_at?->toIso8601String(), 'decided_at' => $x->decided_at?->toIso8601String(),
                'can' => ['decide' => $x->status === 'pending' && $user->can('assessment_exclusions.approve') && $x->requested_by !== $user->id,
                    'withdraw' => $x->status === 'pending' && $x->requested_by === $user->id],
            ])->all();
    }

    private function exceptionReasons(): array
    {
        return AssessmentUnassessedReason::query()->where('is_active', true)->where('source', 'approved_exception')->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_am'])->all();
    }

    /** @return array<string, mixed> */
    private function employeeFilters(Request $request, User $user): array
    {
        $filters = $request->validate([
            'organization_id' => ['nullable', 'uuid'], 'organization_unit_id' => ['nullable', 'uuid'],
            'outcome' => ['nullable', Rule::in([...AssessmentCoverageService::OUTCOMES, 'unassessed'])],
            'gender' => ['nullable', Rule::in(AssessmentCoverageService::GENDERS)], 'reason_id' => ['nullable', 'uuid'],
            'eligibility_status' => ['nullable', Rule::in(['eligible', 'excluded'])], 'form_version_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        abort_if(! empty($filters['gender']) && ! $user->can('assessment_oversight.view_demographics'), 403);
        if (! empty($filters['organization_id'])) {
            $this->access->authorizeOrganization($user, $filters['organization_id'], $filters['organization_unit_id'] ?? null);
        }

        return array_filter($filters, fn ($v) => $v !== null && $v !== '');
    }

    /** @return array<string, mixed> */
    private function aggregateFilters(Request $request, User $user): array
    {
        $filters = array_filter($request->validate(['organization_id' => ['nullable', 'uuid'], 'organization_unit_id' => ['nullable', 'uuid'], 'form_version_id' => ['nullable', 'uuid']]));
        if (! empty($filters['organization_id'])) {
            $this->access->authorizeOrganization($user, $filters['organization_id'], $filters['organization_unit_id'] ?? null);
        }

        return $filters;
    }

    /** @param array<int, string|null> $ids */
    private function groupLabels(string $dimension, array $ids): array
    {
        $ids = array_values(array_filter($ids));

        return match ($dimension) {
            'organization_id' => Organization::query()->whereIn('id', $ids)->get(['id', 'code', 'name_en', 'name_am'])->keyBy('id')->map->only(['id', 'code', 'name_en', 'name_am'])->all(),
            'organization_unit_id' => OrganizationUnit::query()->whereIn('id', $ids)->get(['id', 'name_en', 'name_am'])->keyBy('id')->map->only(['id', 'name_en', 'name_am'])->all(),
            'form_version_id' => AssessmentFormVersion::query()->whereIn('id', $ids)->get(['id', 'name_en', 'name_am', 'version_no'])->keyBy('id')->map->only(['id', 'name_en', 'name_am', 'version_no'])->all(),
            default => [],
        };
    }

    private function organizationOptions(AssessmentCycle $cycle, OversightScope $scope): array
    {
        $query = DB::table('assessment_cycle_organizations as co')->join('organizations as o', 'o.id', '=', 'co.organization_id')
            ->where('co.assessment_cycle_id', $cycle->id)->where('co.status', 'included')->orderBy('o.name_en')->select(['o.id', 'o.code', 'o.name_en', 'o.name_am']);

        return $scope->apply($query, 'o.id')->limit(1000)->get()->map(fn ($o): array => (array) $o)->all();
    }

    private function unitOptions(string $organizationId, OversightScope $scope): array
    {
        return OrganizationUnit::query()->where('organization_id', $organizationId)
            ->when($scope->unitIds !== null, fn ($q) => $q->whereIn('id', $scope->unitIds))
            ->orderBy('name_en')->limit(500)->get(['id', 'name_en', 'name_am'])->all();
    }
}
