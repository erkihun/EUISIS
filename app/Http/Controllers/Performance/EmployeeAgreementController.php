<?php

declare(strict_types=1);

namespace App\Http\Controllers\Performance;

use App\Enums\Performance\AgreementStatus;
use App\Enums\Performance\ReviewType;
use App\Http\Requests\Performance\RecordKpiActualRequest;
use App\Http\Requests\Performance\SaveAgreementItemRequest;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeePerformanceAgreement;
use App\Models\EmployeePerformanceItem;
use App\Models\KpiActual;
use App\Models\KpiTarget;
use App\Models\PerformanceCycle;
use App\Models\PerformanceEvidence;
use App\Models\PerformanceResult;
use App\Models\PerformanceScoreAdjustment;
use App\Models\PerformanceTargetAmendment;
use App\Models\User;
use App\Services\Performance\DevelopmentPlanService;
use App\Services\Performance\EmployeeAgreementService;
use App\Services\Performance\EpmsAccess;
use App\Services\Performance\EpmsSettings;
use App\Services\Performance\KpiActualService;
use App\Services\Performance\PerformanceEvidenceService;
use App\Services\Performance\PerformancePresenter;
use App\Services\Performance\PerformanceResultService;
use App\Services\Performance\PerformanceReviewService;
use App\Services\Performance\TargetAmendmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Manager/HR side of employee agreements. The employee side is MyPerformanceController. */
class EmployeeAgreementController extends PerformanceController
{
    public function __construct(
        private readonly EmployeeAgreementService $agreements,
        private readonly KpiActualService $actuals,
        private readonly PerformanceReviewService $reviews,
        private readonly PerformanceResultService $results,
        private readonly PerformanceEvidenceService $evidence,
        private readonly TargetAmendmentService $amendments,
        private readonly DevelopmentPlanService $development,
        private readonly PerformancePresenter $presenter,
        private readonly EpmsAccess $access,
        private readonly EpmsSettings $settings,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($user->can('employee_performance_agreements.manage') || $user->can('performance_reports.view'), 403);
        $search = trim((string) $request->query('search', ''));

        $query = $this->access->constrainAgreements(EmployeePerformanceAgreement::query(), $user)
            ->with(['employee:id,full_name,name_en,employee_number', 'organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am', 'cycle:id,name_en,name_am'])
            ->when($request->query('cycle_id'), fn ($q, $v) => $q->where('cycle_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($search !== '', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('employee_number', 'like', "%{$search}%")->orWhere('full_name', 'like', "%{$search}%")->orWhere('name_en', 'like', "%{$search}%")));

        return Inertia::render('Performance/Agreements/Index', [
            'agreements' => $query->orderByDesc('updated_at')->paginate(25)->withQueryString()->through(fn (EmployeePerformanceAgreement $a) => [
                'id' => $a->getKey(), 'status' => $a->status->value, 'version' => $a->agreement_version, 'is_temporary' => $a->is_temporary,
                'employee' => ['name' => $a->employee?->full_name, 'name_en' => $a->employee?->name_en, 'number' => $a->employee?->employee_number],
                'organization' => ['name_en' => $a->organization?->name_en, 'name_am' => $a->organization?->name_am],
                'unit' => $a->organizationUnit ? ['name_en' => $a->organizationUnit->name_en, 'name_am' => $a->organizationUnit->name_am] : null,
                'cycle' => ['name_en' => $a->cycle?->name_en, 'name_am' => $a->cycle?->name_am],
                'effective_from' => $a->effective_from->toDateString(), 'effective_to' => $a->effective_to->toDateString(),
            ]),
            'filters' => ['search' => $search, 'cycle_id' => $request->query('cycle_id'), 'status' => $request->query('status')],
            'statuses' => AgreementStatus::values(),
            'cycles' => PerformanceCycle::query()->whereNotIn('status', ['CLOSED', 'CANCELLED', 'DRAFT'])->orderByDesc('start_date')->get(['id', 'name_en', 'name_am', 'status'])->toArray(),
            'can' => ['create' => $user->can('employee_performance_agreements.manage')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'employee_assignment_id' => ['required', 'uuid', 'exists:employee_assignments,id'],
            'cycle_id' => ['required', 'uuid', 'exists:performance_cycles,id'],
            'manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'is_temporary' => ['boolean'],
        ]);
        $assignment = EmployeeAssignment::query()->with('employee')->findOrFail($data['employee_assignment_id']);
        $manager = isset($data['manager_user_id']) ? User::query()->find($data['manager_user_id']) : null;

        $agreement = $this->agreements->create($assignment->employee, $assignment, PerformanceCycle::query()->findOrFail($data['cycle_id']), $request->user(), $manager, (bool) ($data['is_temporary'] ?? false));

        return to_route('performance.agreements.show', $agreement)->with('flash', ['message' => __('performance.saved'), 'type' => 'success']);
    }

    public function show(Request $request, EmployeePerformanceAgreement $agreement): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless(! $this->access->isOwn($user, $agreement) && $this->access->canViewAgreement($user, $agreement), 403);
        $manage = $this->access->canManageAgreement($user, $agreement);
        $status = $agreement->status;

        return Inertia::render('Performance/Agreements/Show', [
            'agreement' => $this->presenter->agreement($agreement, $user),
            'planTargets' => in_array($status, [AgreementStatus::Draft, AgreementStatus::Returned], true) && $agreement->performance_plan_id !== null
                ? KpiTarget::query()->whereIn('performance_plan_id', array_filter([$agreement->performance_plan_id, $agreement->plan?->parent_plan_id]))->where('is_current', true)->with('kpi:id,code,name_en,name_am')->get()
                    ->map(fn ($t) => ['id' => $t->getKey(), 'kpi_id' => $t->kpi_id, 'kpi_code' => $t->kpi->code, 'kpi_name_en' => $t->kpi->name_en, 'target_value' => $t->target_value, 'objective_id' => $t->objective_id])->all()
                : [],
            'validation' => in_array($status, [AgreementStatus::Draft, AgreementStatus::Returned, AgreementStatus::PendingManagerApproval], true) ? $this->agreements->validate($agreement) : [],
            'pendingAdjustments' => PerformanceScoreAdjustment::query()->where('status', 'PENDING')
                ->whereIn('result_id', PerformanceResult::query()->where('agreement_id', $agreement->getKey())->select('id'))->get()->toArray(),
            'pendingAmendments' => PerformanceTargetAmendment::query()->where('subject_type', 'ITEM')->where('status', 'PENDING')
                ->whereIn('subject_id', $agreement->allItems()->select('id'))->get()->toArray(),
            'recommendPip' => ($result = $agreement->results()->where('is_current', true)->first()) !== null && app(DevelopmentPlanService::class)->recommendsImprovementPlan($result),
            'combined' => $this->results->combinedForCycle($agreement->employee_id, $agreement->cycle_id),
            'can' => [
                'manage' => $manage,
                'edit' => $manage && in_array($status, [AgreementStatus::Draft, AgreementStatus::Returned], true),
                'approve' => $manage && $user->can('employee_performance_agreements.approve') && $status === AgreementStatus::PendingManagerApproval,
                'enterActual' => $manage && $user->can('kpi_actuals.enter'),
                'verify' => $manage && $user->can('kpi_actuals.verify'),
                'review' => $manage && $user->can('performance_reviews.manage'),
                'checkin' => $manage && $user->can('performance_checkins.manage'),
                'finalize' => $this->access->inScope($user, 'performance_reviews.finalize', $agreement->organization_id),
                'adjust' => $manage && $this->settings->allowScoreAdjustment(),
                'amend' => $manage && $user->can('kpi_targets.manage'),
            ],
        ]);
    }

    // ── Items ────────────────────────────────────────────────────────────

    public function storeItem(SaveAgreementItemRequest $request, EmployeePerformanceAgreement $agreement): RedirectResponse
    {
        $this->agreements->addItem($agreement, $request->validated(), $request->user());

        return $this->saved();
    }

    public function updateItem(SaveAgreementItemRequest $request, EmployeePerformanceItem $item): RedirectResponse
    {
        $this->agreements->updateItem($item, $request->validated(), $request->user());

        return $this->saved();
    }

    public function destroyItem(Request $request, EmployeePerformanceItem $item): RedirectResponse
    {
        $this->agreements->removeItem($item, $request->user());

        return $this->saved();
    }

    // ── Workflow ─────────────────────────────────────────────────────────

    public function workflow(Request $request, EmployeePerformanceAgreement $agreement, string $action): RedirectResponse
    {
        $this->ensureEnabled();
        $user = $request->user();

        match ($action) {
            'submit' => $this->agreements->submitToEmployee($agreement, $user),
            'approve' => $this->agreements->approve($agreement, $user),
            'return' => $this->agreements->returnAgreement($agreement, (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'], $user),
            'close' => $this->agreements->close($agreement, (string) $request->validate(['end_date' => ['required', 'date_format:Y-m-d']])['end_date'], (string) $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'], $user),
            default => abort(404),
        };

        return $this->saved();
    }

    public function transfer(Request $request, EmployeePerformanceAgreement $agreement): RedirectResponse
    {
        $data = $request->validate(['employee_assignment_id' => ['required', 'uuid', 'exists:employee_assignments,id'], 'is_temporary' => ['boolean']]);
        $assignment = EmployeeAssignment::query()->where('employee_id', $agreement->employee_id)->findOrFail($data['employee_assignment_id']);
        $next = $this->agreements->transfer($agreement, $assignment, $request->user(), (bool) ($data['is_temporary'] ?? false));

        return to_route('performance.agreements.show', $next)->with('flash', ['message' => __('performance.saved'), 'type' => 'success']);
    }

    // ── Actuals & evidence ───────────────────────────────────────────────

    public function recordActual(RecordKpiActualRequest $request, EmployeePerformanceItem $item): RedirectResponse
    {
        $this->actuals->recordForItem($item, $request->validated(), $request->user());

        return $this->saved();
    }

    public function syncActual(Request $request, EmployeePerformanceItem $item): RedirectResponse
    {
        $this->access->authorize($this->access->canManageAgreement($request->user(), $item->agreement));
        $data = $request->validate(['period_start' => ['required', 'date_format:Y-m-d'], 'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start']]);
        $from = Carbon::parse($data['period_start'])->max($item->agreement->effective_from);
        $to = Carbon::parse($data['period_end'])->min($item->agreement->effective_to);
        $this->actuals->syncDailyActivity($item, $from, $to) ?? $this->actuals->syncSystem($item, $from, $to);

        return $this->saved();
    }

    public function verifyActual(Request $request, KpiActual $actual): RedirectResponse
    {
        $this->actuals->verify($actual, $request->user());

        return $this->saved();
    }

    public function storeEvidence(Request $request, EmployeePerformanceAgreement $agreement): RedirectResponse
    {
        $data = $this->validateEvidence($request);
        $this->evidence->add($agreement, $data, $request->file('file'), $request->user());

        return $this->saved();
    }

    public function verifyEvidence(Request $request, PerformanceEvidence $evidence): RedirectResponse
    {
        $this->evidence->verify($evidence, $request->user());

        return $this->saved();
    }

    public function downloadEvidence(Request $request, PerformanceEvidence $evidence): BinaryFileResponse
    {
        abort_unless($this->evidence->canDownload($request->user(), $evidence), 403);

        return response()->download($this->evidence->path($evidence), $evidence->original_name ?? 'evidence', [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    // ── Check-ins & reviews ──────────────────────────────────────────────

    public function storeCheckin(Request $request, EmployeePerformanceAgreement $agreement): RedirectResponse
    {
        $data = $request->validate([
            'checkin_date' => ['required', 'date_format:Y-m-d'], 'period_start' => ['nullable', 'date_format:Y-m-d'], 'period_end' => ['nullable', 'date_format:Y-m-d'],
            'progress_status' => ['required', Rule::in(['ON_TRACK', 'AT_RISK', 'OFF_TRACK', 'NOT_REPORTED'])],
            'manager_comment' => ['nullable', 'string', 'max:5000'], 'manager_private_note' => ['nullable', 'string', 'max:5000'],
            'blockers' => ['nullable', 'string', 'max:5000'], 'support_required' => ['nullable', 'string', 'max:5000'],
            'learning_needs' => ['nullable', 'string', 'max:5000'], 'next_actions' => ['nullable', 'string', 'max:5000'],
        ]);
        $this->reviews->addCheckin($agreement, $data, $request->user());

        return $this->saved();
    }

    public function completeReview(Request $request, EmployeePerformanceAgreement $agreement, string $type): RedirectResponse
    {
        $reviewType = ReviewType::from(strtoupper($type));
        $data = $request->validate([
            'manager_comment' => ['nullable', 'string', 'max:10000'], 'manager_private_note' => ['nullable', 'string', 'max:10000'],
            'improvement_actions' => ['nullable', 'string', 'max:10000'], 'at_risk_item_ids' => ['nullable', 'array'], 'at_risk_item_ids.*' => ['uuid'],
            'ratings' => ['nullable', 'array'], 'ratings.*' => ['integer', 'min:1', 'max:10'],
        ]);
        $this->reviews->managerComplete($agreement, $reviewType, $data, $request->user());

        return $this->saved();
    }

    public function returnReview(Request $request, EmployeePerformanceAgreement $agreement, string $type): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->reviews->managerReturn($agreement, ReviewType::from(strtoupper($type)), $data['reason'], $request->user());

        return $this->saved();
    }

    // ── Results ──────────────────────────────────────────────────────────

    public function calculate(Request $request, EmployeePerformanceAgreement $agreement): RedirectResponse
    {
        $this->results->calculate($agreement, $request->user());

        return $this->saved();
    }

    public function resultAction(Request $request, PerformanceResult $result, string $action): RedirectResponse
    {
        match ($action) {
            'finalize' => $this->results->finalize($result, $request->user()),
            'release' => $this->results->release($result, $request->user()),
            default => abort(404),
        };

        return $this->saved();
    }

    public function requestAdjustment(Request $request, PerformanceResult $result): RedirectResponse
    {
        $data = $request->validate(['adjusted_score' => ['required', 'numeric', 'min:0', 'max:200'], 'reason' => ['required', 'string', 'max:2000']]);
        $this->results->requestAdjustment($result, (string) $data['adjusted_score'], $data['reason'], $request->user());

        return $this->saved();
    }

    public function decideAdjustment(Request $request, PerformanceScoreAdjustment $adjustment): RedirectResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        $this->results->decideAdjustment($adjustment, (bool) $data['approve'], $data['note'] ?? null, $request->user());

        return $this->saved();
    }

    public function requestAmendment(Request $request, EmployeePerformanceItem $item): RedirectResponse
    {
        $data = $request->validate([
            'target_value' => ['nullable', 'numeric'], 'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reason' => ['required', 'string', 'max:2000'], 'effective_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $this->amendments->request($item, array_filter(['target_value' => $data['target_value'] ?? null, 'weight' => $data['weight'] ?? null], fn ($v) => $v !== null), $data['reason'], $data['effective_date'], $request->user());

        return $this->saved();
    }

    public function decideAmendment(Request $request, PerformanceTargetAmendment $amendment): RedirectResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean']]);
        $this->amendments->decide($amendment, (bool) $data['approve'], $request->user());

        return $this->saved();
    }

    public function storeImprovementPlan(Request $request, EmployeePerformanceAgreement $agreement): RedirectResponse
    {
        $data = $request->validate([
            'identified_gap' => ['required', 'string', 'max:5000'], 'required_improvement' => ['required', 'string', 'max:5000'],
            'support_action' => ['nullable', 'string', 'max:5000'], 'training' => ['nullable', 'string', 'max:5000'], 'manager_support' => ['nullable', 'string', 'max:5000'],
            'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after:start_date'],
            'review_dates' => ['nullable', 'array'], 'review_dates.*' => ['date_format:Y-m-d'],
        ]);
        $this->development->createImprovementPlan($agreement, $data, $request->user());

        return $this->saved();
    }

    public function storeDevelopmentPlan(Request $request, EmployeePerformanceAgreement $agreement): RedirectResponse
    {
        $this->development->createDevelopmentPlan($agreement, $this->validateIdp($request), $request->user());

        return $this->saved();
    }

    /** @return array<string, mixed> */
    public static function validateIdp(Request $request): array
    {
        return $request->validate([
            'competency_id' => ['nullable', 'uuid', 'exists:competencies,id'], 'competency_gap' => ['nullable', 'string', 'max:5000'],
            'development_objective' => ['required', 'string', 'max:5000'], 'training' => ['nullable', 'string', 'max:5000'],
            'coaching' => ['nullable', 'string', 'max:5000'], 'expected_outcome' => ['nullable', 'string', 'max:5000'], 'due_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }

    /** @return array<string, mixed> */
    public static function validateEvidence(Request $request): array
    {
        return $request->validate([
            'employee_performance_item_id' => ['nullable', 'uuid'],
            'daily_activity_item_id' => ['nullable', 'uuid'],
            'evidence_type' => ['nullable', Rule::in(['SYSTEM_RECORD', 'MANAGER_CONFIRMATION', 'OTHER', 'DOCUMENT'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Sniffed MIME allowlist; no HTML/SVG/executables.
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'],
        ]);
    }
}
