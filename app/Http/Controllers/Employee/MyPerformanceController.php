<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Enums\Performance\ReviewType;
use App\Enums\Performance\AgreementStatus;
use App\Enums\Performance\AppealStatus;
use App\Enums\Performance\ResultStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Performance\EmployeeAgreementController;
use App\Models\EmployeePerformanceAgreement;
use App\Models\PerformanceAppeal;
use App\Models\PerformanceCheckin;
use App\Models\PerformanceResult;
use App\Services\Performance\DevelopmentPlanService;
use App\Services\Performance\EmployeeAgreementService;
use App\Services\Performance\EpmsAccess;
use App\Services\Performance\EpmsSettings;
use App\Services\Performance\PerformanceAppealService;
use App\Services\Performance\PerformanceEvidenceService;
use App\Services\Performance\PerformancePresenter;
use App\Services\Performance\PerformanceReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Portal → My Performance. Only the signed-in employee's own records;
 * nothing here accepts an employee id from the browser.
 */
class MyPerformanceController extends Controller
{
    public function __construct(
        private readonly EpmsAccess $access,
        private readonly EpmsSettings $settings,
        private readonly PerformancePresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($this->settings->enabled(), 404);
        $user = $request->user();
        abort_unless($user->can('employee_performance_agreements.view_own'), 403);
        $employee = $this->access->employeeOf($user);

        $agreements = $employee === null ? collect() : EmployeePerformanceAgreement::query()
            ->where('employee_id', $employee->getKey())->with('cycle')
            ->orderByDesc('effective_from')->get();
        $selected = $agreements->firstWhere('id', $request->query('agreement'))
            ?? $agreements->first(fn ($agreement) => $agreement->status !== AgreementStatus::Closed)
            ?? $agreements->first();
        $result = $selected?->results()->where('is_current', true)->first();
        $appealDeadline = $result?->released_at?->copy()->addDays($this->settings->appealWindowDays())->endOfDay();
        $reviews = app(PerformanceReviewService::class);

        return Inertia::render('Employee/MyPerformance', [
            'agreements' => $agreements->map(fn ($a) => ['id' => $a->getKey(), 'status' => $a->status->value, 'cycle' => $a->cycle?->only(['name_en', 'name_am']),
                'effective_from' => $a->effective_from->toDateString(), 'effective_to' => $a->effective_to->toDateString(), 'is_temporary' => $a->is_temporary])->all(),
            'agreement' => $selected ? $this->presenter->agreement($selected, $user) : null,
            'appeals' => $employee === null ? [] : PerformanceAppeal::query()->where('employee_id', $employee->getKey())->latest('submitted_at')->get()
                ->map(fn ($a) => ['id' => $a->getKey(), 'appeal_no' => $a->appeal_no, 'status' => $a->status->value, 'decision' => $a->decision?->value,
                    'decision_reason' => $a->decision_reason, 'decided_score' => $a->decided_score, 'submitted_at' => $a->submitted_at->toIso8601String()])->all(),
            'appealWindowDays' => $this->settings->appealWindowDays(),
            'selfAssessmentRequired' => $this->settings->requireYearendSelfAssessment(),
            'can' => [
                'selfAssess' => collect(ReviewType::cases())->mapWithKeys(fn ($type) => [
                    $type->value => $selected !== null && $reviews->canSubmitSelfAssessment($selected, $type, $user),
                ])->all(),
                'appeal' => $result !== null && $result->status === ResultStatus::Released
                    && $user->can('performance_appeals.create') && $appealDeadline !== null && now()->lte($appealDeadline)
                    && ! PerformanceAppeal::query()->where('result_id', $result->getKey())
                        ->whereIn('status', [AppealStatus::Submitted, AppealStatus::UnderReview])->exists(),
            ],
        ]);
    }

    public function acknowledge(Request $request, EmployeePerformanceAgreement $agreement, EmployeeAgreementService $agreements): RedirectResponse
    {
        $agreements->acknowledge($agreement, $request->user());

        return $this->saved();
    }

    public function returnAgreement(Request $request, EmployeePerformanceAgreement $agreement, EmployeeAgreementService $agreements): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $agreements->returnAgreement($agreement, $data['reason'], $request->user());

        return $this->saved();
    }

    public function submitReview(Request $request, EmployeePerformanceAgreement $agreement, string $type, PerformanceReviewService $reviews): RedirectResponse
    {
        $data = $request->validate([
            'employee_self_assessment' => ['required', 'string', 'max:10000'], 'achievements' => ['nullable', 'string', 'max:10000'],
            'challenges' => ['nullable', 'string', 'max:10000'], 'contributions' => ['nullable', 'string', 'max:10000'],
            'development_needs' => ['nullable', 'string', 'max:10000'], 'self_ratings' => ['nullable', 'array'], 'self_ratings.*' => ['integer', 'min:1', 'max:10'],
        ]);
        $reviews->employeeSubmit($agreement, ReviewType::from(strtoupper($type)), $data, $request->user());

        return $this->saved();
    }

    public function checkinNote(Request $request, PerformanceCheckin $checkin, PerformanceReviewService $reviews): RedirectResponse
    {
        $data = $request->validate([
            'employee_summary' => ['nullable', 'string', 'max:5000'], 'blockers' => ['nullable', 'string', 'max:5000'],
            'support_required' => ['nullable', 'string', 'max:5000'], 'learning_needs' => ['nullable', 'string', 'max:5000'],
        ]);
        $reviews->employeeCheckinNote($checkin, $data, $request->user());

        return $this->saved();
    }

    public function storeEvidence(Request $request, EmployeePerformanceAgreement $agreement, PerformanceEvidenceService $evidence): RedirectResponse
    {
        $evidence->add($agreement, EmployeeAgreementController::validateEvidence($request), $request->file('file'), $request->user());

        return $this->saved();
    }

    public function storeDevelopmentPlan(Request $request, EmployeePerformanceAgreement $agreement, DevelopmentPlanService $development): RedirectResponse
    {
        $development->createDevelopmentPlan($agreement, EmployeeAgreementController::validateIdp($request), $request->user());

        return $this->saved();
    }

    public function appeal(Request $request, PerformanceResult $result, PerformanceAppealService $appeals): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:10000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx'],
        ]);
        $appeals->file($result, $data['reason'], $request->file('attachment'), $request->user());

        return $this->saved();
    }

    private function saved(): RedirectResponse
    {
        return back()->with('flash', ['message' => __('performance.saved'), 'type' => 'success']);
    }
}
