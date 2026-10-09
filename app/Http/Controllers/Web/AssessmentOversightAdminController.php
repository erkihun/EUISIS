<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\AssessmentCycleEligibility;
use App\Models\AssessmentExclusionRequest;
use App\Models\AssessmentInstitutionSubmission;
use App\Models\AssessmentResultBandPolicy;
use App\Models\AssessmentType;
use App\Models\AssessmentUnassessedReason;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assessment\Oversight\AssessmentCycleService;
use App\Services\Assessment\Oversight\AssessmentEligibilityService;
use App\Services\Assessment\Oversight\AssessmentInstitutionSubmissionService;
use App\Services\Assessment\Oversight\AssessmentResultDistributionService;
use App\Services\Assessment\Oversight\OversightAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Oversight configuration and the controlled actions that change the
 * denominator or a submission. Each action re-checks its own permission and
 * the organization scope; the services audit every change.
 */
class AssessmentOversightAdminController extends Controller
{
    public function __construct(
        private readonly OversightAccess $access,
        private readonly AssessmentCycleService $cycles,
        private readonly AssessmentEligibilityService $eligibility,
        private readonly AssessmentResultDistributionService $bands,
        private readonly AssessmentInstitutionSubmissionService $submissions,
    ) {}

    public function setup(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canAny(['assessment_oversight.manage_cycles', 'assessment_oversight.manage_policies']), 403);

        return Inertia::render('Assessments/Oversight/Setup', [
            'cycles' => AssessmentCycle::query()->with('type:id,code,name_en,name_am')->withCount(['organizations' => fn ($q) => $q->where('status', 'included')])
                ->orderByDesc('period_end')->paginate(20)->withQueryString()
                ->through(fn (AssessmentCycle $c): array => [...$this->cycleData($c), 'type' => $c->type?->only(['code', 'name_en', 'name_am']), 'organizations_count' => $c->organizations_count]),
            'policies' => $this->bands->policies()->map(fn (AssessmentResultBandPolicy $p): array => [
                ...$p->only(['id', 'code', 'version_no', 'name_en', 'name_am', 'status', 'range_min', 'range_max', 'requires_full_coverage']),
                'activated_at' => $p->activated_at?->toIso8601String(),
                'bands' => $p->bands->map->only(['code', 'label_en', 'label_am', 'min_score', 'max_score', 'min_inclusive', 'max_inclusive'])->all(),
                'problems' => $p->isEditable() ? $this->bands->validate($p) : [],
            ])->all(),
            'reasons' => AssessmentUnassessedReason::query()->orderBy('sort_order')->get(),
            'types' => AssessmentType::query()->orderBy('code')->get(['id', 'code', 'name_en', 'name_am']),
            'employeeStatuses' => array_map(fn (EmployeeStatus $s): string => $s->value, EmployeeStatus::cases()),
            'can' => ['cycles' => $user->can('assessment_oversight.manage_cycles'), 'policies' => $user->can('assessment_oversight.manage_policies')],
        ]);
    }

    public function cycle(Request $request, AssessmentCycle $cycle): Response
    {
        $user = $this->manager($request);
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? null;

        return Inertia::render('Assessments/Oversight/Cycle', [
            'cycle' => $this->cycleData($cycle),
            'participants' => $cycle->organizations()->with('organization:id,code,name_en,name_am')->orderBy('status')->get()
                ->map(fn ($row): array => [...$row->only(['id', 'organization_id', 'status', 'submission_status', 'exclusion_reason']), 'organization' => $row->organization?->only(['id', 'code', 'name_en', 'name_am'])])->all(),
            'organizations' => Organization::query()->whereNotIn('id', $cycle->organizations()->where('status', 'included')->select('organization_id'))
                ->when($search, fn ($q, $v) => $q->where(fn ($w) => $w->where('name_en', 'like', "%{$v}%")->orWhere('name_am', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%")))
                ->orderBy('name_en')->limit(50)->get(['id', 'code', 'name_en', 'name_am']),
            'eligibility' => [
                'eligible' => $cycle->eligibility()->where('eligibility_status', 'eligible')->count(),
                'excluded' => $cycle->eligibility()->where('eligibility_status', 'excluded')->count(),
                'by_reason' => $cycle->eligibility()->where('eligibility_status', 'excluded')->join('assessment_unassessed_reasons as r', 'r.id', '=', 'assessment_cycle_employee_eligibility.reason_id')
                    ->selectRaw('r.code, r.name_en, r.name_am, COUNT(*) as n')->groupBy('r.code', 'r.name_en', 'r.name_am')->get(),
            ],
            'pending' => AssessmentExclusionRequest::query()->where('assessment_cycle_id', $cycle->id)->where('status', 'pending')->count(),
            'policies' => AssessmentResultBandPolicy::query()->where('status', 'active')->orWhere('id', $cycle->result_band_policy_id)->get(['id', 'code', 'version_no', 'name_en', 'name_am', 'status']),
            'types' => AssessmentType::query()->orderBy('code')->get(['id', 'code', 'name_en', 'name_am']),
            'employeeStatuses' => array_map(fn (EmployeeStatus $s): string => $s->value, EmployeeStatus::cases()),
            'search' => $search,
        ]);
    }

    public function storeCycle(Request $request): RedirectResponse
    {
        $user = $this->manager($request);
        $cycle = $this->cycles->save($user, null, $this->cycleInput($request));

        return redirect()->route('assessment-oversight.cycles.show', $cycle)->with('success', __('assessments.oversight.cycle_saved'));
    }

    public function updateCycle(Request $request, AssessmentCycle $cycle): RedirectResponse
    {
        $this->cycles->save($this->manager($request), $cycle, $this->cycleInput($request, $cycle));

        return back()->with('success', __('assessments.oversight.cycle_saved'));
    }

    public function cycleStatus(Request $request, AssessmentCycle $cycle): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', Rule::in(['active', 'closed'])]])['status'];
        $this->cycles->setStatus($this->manager($request), $cycle, $status);

        return back()->with('success', __('assessments.oversight.cycle_saved'));
    }

    public function addOrganizations(Request $request, AssessmentCycle $cycle): RedirectResponse
    {
        $user = $this->manager($request);
        $ids = $request->validate(['organization_ids' => ['required', 'array', 'min:1', 'max:500'], 'organization_ids.*' => ['uuid', 'exists:organizations,id']])['organization_ids'];
        foreach ($ids as $id) {
            $this->access->authorizeOrganization($user, $id);
        }
        $this->cycles->addOrganizations($user, $cycle, $ids);

        return back()->with('success', __('assessments.oversight.participation_saved'));
    }

    public function removeOrganization(Request $request, AssessmentCycle $cycle, Organization $organization): RedirectResponse
    {
        $user = $this->manager($request);
        $this->access->authorizeOrganization($user, $organization->id);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];
        $this->cycles->removeOrganization($user, $cycle, $organization->id, $reason);

        return back()->with('success', __('assessments.oversight.participation_saved'));
    }

    public function snapshot(Request $request, AssessmentCycle $cycle): RedirectResponse
    {
        $user = $this->manager($request);
        abort_unless($this->access->scopeFor($user)->isCityWide(), 403);
        $counts = $this->eligibility->snapshotCycleEligibility($user, $cycle);

        return back()->with('success', __('assessments.oversight.snapshot_created', $counts));
    }

    /** Queue evaluator-assignment generation for the cycle (chunked jobs; nothing heavy in this request). */
    public function generateAssignments(Request $request, AssessmentCycle $cycle, \App\Services\Assessment\Execution\AssessmentAssignmentService $assignments): RedirectResponse
    {
        $assignments->queueGeneration($request->user(), $cycle);

        return back()->with('success', __('assessments.execution.generation_queued'));
    }

    public function finalizeEligibility(Request $request, AssessmentCycle $cycle): RedirectResponse
    {
        $user = $this->manager($request);
        abort_unless($this->access->scopeFor($user)->isCityWide(), 403);
        $this->eligibility->finalize($user, $cycle);

        return back()->with('success', __('assessments.oversight.eligibility_finalized'));
    }

    public function requestExclusion(Request $request, AssessmentCycle $cycle): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('assessment_exclusions.request'), 403);
        $data = $request->validate(['eligibility_id' => ['required', 'uuid'], 'reason_id' => ['required', 'uuid', 'exists:assessment_unassessed_reasons,id'], 'note' => ['required', 'string', 'max:2000']]);
        $row = AssessmentCycleEligibility::query()->where('assessment_cycle_id', $cycle->id)->findOrFail($data['eligibility_id']);
        $this->access->authorizeOrganization($user, $row->organization_id, $row->organization_unit_id);
        $this->eligibility->requestExclusion($user, $row, AssessmentUnassessedReason::query()->findOrFail($data['reason_id']), $data['note']);

        return back()->with('success', __('assessments.oversight.exclusion_requested'));
    }

    public function decideExclusion(Request $request, AssessmentExclusionRequest $exclusion): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('assessment_exclusions.approve'), 403);
        $this->access->authorizeOrganization($user, $exclusion->organization_id);
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        $this->eligibility->decide($user, $exclusion, (bool) $data['approve'], $data['note'] ?? null);

        return back()->with('success', __('assessments.oversight.exclusion_decided'));
    }

    public function withdrawExclusion(Request $request, AssessmentExclusionRequest $exclusion): RedirectResponse
    {
        $this->eligibility->withdraw($request->user(), $exclusion);

        return back();
    }

    public function restoreEligibility(Request $request, AssessmentCycleEligibility $eligibility): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('assessment_exclusions.approve'), 403);
        $this->access->authorizeOrganization($user, $eligibility->organization_id);
        $note = $request->validate(['note' => ['required', 'string', 'max:2000']])['note'];
        $this->eligibility->restoreEligibility($user, $eligibility, $note);

        return back()->with('success', __('assessments.oversight.eligibility_restored'));
    }

    public function storePolicy(Request $request): RedirectResponse
    {
        $user = $this->policyManager($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_\-]+$/', 'unique:assessment_result_band_policies,code'], 'name_en' => ['required', 'string', 'max:255'], 'name_am' => ['nullable', 'string', 'max:255']]);
        AssessmentResultBandPolicy::query()->create([...$data, 'version_no' => 1, 'status' => 'draft', 'created_by' => $user->id]);

        return back()->with('success', __('assessments.oversight.policy_saved'));
    }

    public function savePolicy(Request $request, AssessmentResultBandPolicy $policy): RedirectResponse
    {
        $user = $this->policyManager($request);
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:255'], 'name_am' => ['nullable', 'string', 'max:255'],
            'range_min' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999'], 'range_max' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999'],
            'requires_full_coverage' => ['required', 'boolean'],
            'bands' => ['required', 'array', 'min:1', 'max:20'],
            'bands.*.code' => ['required', 'string', 'max:40', 'distinct'], 'bands.*.label_en' => ['required', 'string', 'max:255'], 'bands.*.label_am' => ['nullable', 'string', 'max:255'],
            'bands.*.min_score' => ['required', 'numeric', 'decimal:0,4'], 'bands.*.max_score' => ['required', 'numeric', 'decimal:0,4'],
            'bands.*.min_inclusive' => ['required', 'boolean'], 'bands.*.max_inclusive' => ['required', 'boolean'],
        ]);
        $bands = $data['bands'];
        unset($data['bands']);
        $this->bands->saveDraft($user, $policy, $data, $bands);

        return back()->with('success', __('assessments.oversight.policy_saved'));
    }

    public function activatePolicy(Request $request, AssessmentResultBandPolicy $policy): RedirectResponse
    {
        $this->bands->activate($this->policyManager($request), $policy);

        return back()->with('success', __('assessments.oversight.policy_activated'));
    }

    public function newPolicyVersion(Request $request, AssessmentResultBandPolicy $policy): RedirectResponse
    {
        $this->bands->newVersion($this->policyManager($request), $policy);

        return back()->with('success', __('assessments.oversight.policy_saved'));
    }

    public function storeReason(Request $request): RedirectResponse
    {
        $user = $this->policyManager($request);
        $this->cycles->saveReason($user, null, $this->reasonInput($request) + $request->validate(['code' => ['required', 'string', 'max:20', 'regex:/^[a-z0-9_]+$/', 'unique:assessment_unassessed_reasons,code']]));

        return back()->with('success', __('assessments.oversight.reason_saved'));
    }

    public function updateReason(Request $request, AssessmentUnassessedReason $reason): RedirectResponse
    {
        $this->cycles->saveReason($this->policyManager($request), $reason, $this->reasonInput($request, $reason));

        return back()->with('success', __('assessments.oversight.reason_saved'));
    }

    public function submit(Request $request, AssessmentCycle $cycle): RedirectResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'uuid', 'exists:organizations,id'], 'note' => ['nullable', 'string', 'max:2000']]);
        $this->submissions->createSubmission($request->user(), $cycle, $data['organization_id'], $data['note'] ?? null);

        return back()->with('success', __('assessments.oversight.submitted'));
    }

    public function moveSubmission(Request $request, AssessmentInstitutionSubmission $submission, string $action): RedirectResponse
    {
        $comment = $request->validate(['comment' => [in_array($action, ['return', 'reject'], true) ? 'required' : 'nullable', 'string', 'max:2000']])['comment'] ?? null;
        $user = $request->user();
        match ($action) {
            'start-review' => $this->submissions->startReview($user, $submission, $comment),
            'return' => $this->submissions->returnForCorrection($user, $submission, (string) $comment),
            'reject' => $this->submissions->reject($user, $submission, (string) $comment),
            'verify' => $this->submissions->verify($user, $submission, $comment),
            'finalize' => $this->submissions->finalize($user, $submission, $comment),
        };

        return back()->with('success', __('assessments.oversight.submission_'.$action));
    }

    private function manager(Request $request): User
    {
        abort_unless($request->user()->can('assessment_oversight.manage_cycles'), 403);

        return $request->user();
    }

    private function policyManager(Request $request): User
    {
        abort_unless($request->user()->can('assessment_oversight.manage_policies'), 403);

        return $request->user();
    }

    /** @return array<string, mixed> */
    private function cycleInput(Request $request, ?AssessmentCycle $cycle = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('assessment_cycles', 'code')->ignore($cycle?->id)],
            'name_en' => ['required', 'string', 'max:255'], 'name_am' => ['nullable', 'string', 'max:255'],
            'assessment_type_id' => ['required', 'uuid', 'exists:assessment_types,id'],
            'period_start' => ['required', 'date_format:Y-m-d'], 'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'reference_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start', 'before_or_equal:period_end'],
            'submission_deadline' => ['nullable', 'date_format:Y-m-d'], 'verification_deadline' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:submission_deadline'],
            'result_band_policy_id' => ['nullable', 'uuid', 'exists:assessment_result_band_policies,id'],
            'eligible_employee_statuses' => ['nullable', 'array'], 'eligible_employee_statuses.*' => [Rule::enum(EmployeeStatus::class)],
            'population_rule' => ['required', Rule::in(['all_assigned', 'target_rules'])],
            'min_service_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'exclusion_reduces_denominator' => ['nullable', 'boolean'],
            'small_group_threshold' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'reminder_days_before' => ['nullable', 'integer', 'min:0', 'max:365'],
            'evaluation_due_date' => ['nullable', 'date_format:Y-m-d'],
            'late_submission_policy' => ['nullable', Rule::in(['block', 'allow_with_reason', 'allow_flagged'])],
        ]);
        $data['evaluation_due_date'] = $data['evaluation_due_date'] ?? null;
        $data['late_submission_policy'] = $data['late_submission_policy'] ?? null;
        foreach (['min_service_days', 'small_group_threshold', 'reminder_days_before'] as $field) {
            $data[$field] = isset($data[$field]) && $data[$field] !== '' ? (int) $data[$field] : null;
        }
        $data['exclusion_reduces_denominator'] = isset($data['exclusion_reduces_denominator']) ? (bool) $data['exclusion_reduces_denominator'] : null;
        $data['eligible_employee_statuses'] = empty($data['eligible_employee_statuses']) ? null : array_values($data['eligible_employee_statuses']);
        $data['result_band_policy_id'] = $data['result_band_policy_id'] ?? null;

        return $data;
    }

    /** @return array<string, mixed> */
    private function reasonInput(Request $request, ?AssessmentUnassessedReason $reason = null): array
    {
        return $request->validate([
            'name_en' => ['required', 'string', 'max:255'], 'name_am' => ['nullable', 'string', 'max:255'],
            // System-detected reasons keep their source; the service only lets their wording change.
            'source' => ['required', Rule::in($reason?->is_system ? [$reason->source] : ['institution_reported', 'approved_exception'])],
            'requires_approval' => ['required', 'boolean'], 'excludes_from_denominator' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function cycleData(AssessmentCycle $c): array
    {
        return [
            ...$c->only(['id', 'code', 'name_en', 'name_am', 'assessment_type_id', 'status', 'eligibility_status', 'result_band_policy_id', 'eligible_employee_statuses',
                'population_rule', 'min_service_days', 'exclusion_reduces_denominator', 'small_group_threshold', 'reminder_days_before',
                'late_submission_policy', 'assignment_generation_status']),
            'evaluation_due_date' => $c->evaluation_due_date?->toDateString(), 'assignments_generated_at' => $c->assignments_generated_at?->toIso8601String(),
            'period_start' => $c->period_start?->toDateString(), 'period_end' => $c->period_end?->toDateString(), 'reference_date' => $c->reference_date?->toDateString(),
            'submission_deadline' => $c->submission_deadline?->toDateString(), 'verification_deadline' => $c->verification_deadline?->toDateString(),
            'eligibility_snapshot_at' => $c->eligibility_snapshot_at?->toIso8601String(), 'eligibility_finalized_at' => $c->eligibility_finalized_at?->toIso8601String(),
        ];
    }
}
