<?php

declare(strict_types=1);

namespace Modules\Performance\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Performance\Application\Services\DevelopmentPlanService;
use Modules\Performance\Application\Services\EmployeeAgreementService;
use Modules\Performance\Application\Services\EpmsAccess;
use Modules\Performance\Application\Services\EpmsSettings;
use Modules\Performance\Application\Services\PerformanceAppealService;
use Modules\Performance\Application\Services\PerformanceEvidenceService;
use Modules\Performance\Application\Services\PerformancePresenter;
use Modules\Performance\Application\Services\PerformanceReviewService;
use Modules\Performance\Contracts\Data\AgreementStatus;
use Modules\Performance\Contracts\Data\AppealStatus;
use Modules\Performance\Contracts\Data\ResultStatus;
use Modules\Performance\Contracts\Data\ReviewType;
use Modules\Performance\Domain\Models\EmployeePerformanceAgreement;
use Modules\Performance\Domain\Models\PerformanceAppeal;
use Modules\Performance\Domain\Models\PerformanceCheckin;
use Modules\Performance\Domain\Models\PerformanceEvidence;
use Modules\Performance\Domain\Models\PerformanceResult;
use Modules\Performance\Http\Controllers\EmployeeAgreementController;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $this->own($request, $agreement);
        $agreements->acknowledge($agreement, $request->user());

        return $this->saved();
    }

    public function returnAgreement(Request $request, EmployeePerformanceAgreement $agreement, EmployeeAgreementService $agreements): RedirectResponse
    {
        $this->own($request, $agreement);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $agreements->returnAgreement($agreement, $data['reason'], $request->user());

        return $this->saved();
    }

    public function submitReview(Request $request, EmployeePerformanceAgreement $agreement, string $type, PerformanceReviewService $reviews): RedirectResponse
    {
        $this->own($request, $agreement);
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
        $this->own($request, $checkin->agreement);
        $data = $request->validate([
            'employee_summary' => ['nullable', 'string', 'max:5000'], 'blockers' => ['nullable', 'string', 'max:5000'],
            'support_required' => ['nullable', 'string', 'max:5000'], 'learning_needs' => ['nullable', 'string', 'max:5000'],
        ]);
        $reviews->employeeCheckinNote($checkin, $data, $request->user());

        return $this->saved();
    }

    public function storeEvidence(Request $request, EmployeePerformanceAgreement $agreement, PerformanceEvidenceService $evidence): RedirectResponse
    {
        $this->own($request, $agreement);
        $evidence->add($agreement, EmployeeAgreementController::validateEvidence($request), $request->file('file'), $request->user());

        return $this->saved();
    }

    public function storeDevelopmentPlan(Request $request, EmployeePerformanceAgreement $agreement, DevelopmentPlanService $development): RedirectResponse
    {
        $this->own($request, $agreement);
        $development->createDevelopmentPlan($agreement, EmployeeAgreementController::validateIdp($request), $request->user());

        return $this->saved();
    }

    public function appeal(Request $request, PerformanceResult $result, PerformanceAppealService $appeals): RedirectResponse
    {
        $this->own($request, $result->agreement);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:10000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx'],
        ]);
        $appeals->file($result, $data['reason'], $request->file('attachment'), $request->user());

        return $this->saved();
    }

    /**
     * My Portal acts on the employee's own records only. Checked before
     * validation, so another employee's record id is refused outright
     * rather than answered with validation errors that confirm it exists.
     */
    private function own(Request $request, ?EmployeePerformanceAgreement $agreement): void
    {
        $this->access->authorize($agreement !== null && $this->access->isOwn($request->user(), $agreement));
    }

    private function saved(): RedirectResponse
    {
        return back()->with('flash', ['message' => __('performance.saved'), 'type' => 'success']);
    }

    /** The employee's own evidence file; a manager's copy is served under the management routes. */
    public function downloadEvidence(Request $request, PerformanceEvidence $evidence): StreamedResponse
    {
        $user = $request->user();
        abort_unless($evidence->file_path !== null && $this->access->isOwn($user, $evidence->agreement) && $user->can('employee_performance_agreements.view_own'), 403);

        return Storage::disk('local')->download($evidence->file_path, $evidence->original_name ?? 'evidence', [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
