<?php

declare(strict_types=1);

namespace App\Http\Controllers\Grievances;

use App\Enums\Grievance\GrievanceEvidenceType;
use App\Enums\Grievance\GrievanceReasonCodeType;
use App\Enums\GrievanceStatus;
use App\Http\Controllers\Controller;
use App\Models\Grievance;
use App\Models\GrievanceCategory;
use App\Models\GrievanceInformationRequest;
use App\Models\GrievanceLetter;
use App\Models\GrievanceReasonCode;
use App\Rules\RichTextContent;
use App\Services\Grievances\GrievanceAppealService;
use App\Services\Grievances\GrievanceCaseAccessService;
use App\Services\Grievances\GrievanceCaseService;
use App\Services\Grievances\GrievanceCorrespondenceService;
use App\Services\Grievances\GrievanceEvidenceService;
use App\Services\Grievances\GrievanceInformationService;
use App\Services\Grievances\GrievancePresenter;
use App\Services\Grievances\GrievanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * My Portal → My Grievances (docs/grievance-management.md §4.1). Everything
 * here is about the signed-in employee's own cases; the employee never picks
 * a handler, committee, organization or approver.
 */
class GrievancePortalController extends Controller
{
    /** Largest file the grievance application form accepts: 2 MB. */
    public const FORM_FILE_MAX_KB = 2048;

    public function __construct(
        private readonly GrievanceCaseService $cases,
        private readonly GrievanceCaseAccessService $access,
        private readonly GrievancePresenter $presenter,
        private readonly GrievanceSettings $settings,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('grievances.view_own'), 403);

        $own = fn () => Grievance::query()->where(fn ($q) => $q->where('submitted_by_user_id', $user->getKey())
            ->when($user->employee_id, fn ($q2) => $q2->orWhere('employee_id', $user->employee_id)));

        $query = $own()->with(GrievancePresenter::listRelations())->latest();
        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }

        $summaryBase = $own();

        return Inertia::render('Grievances/Portal/Index', [
            'grievances' => $query->paginate(15)->withQueryString()->through(fn (Grievance $g) => $this->presenter->listRow($g)),
            'filters' => $request->only('status'),
            'statuses' => GrievanceStatus::values(),
            'summary' => [
                'open' => (clone $summaryBase)->whereNotIn('status', ['draft', 'closed', 'withdrawn', 'rejected_at_intake'])->count(),
                'drafts' => (clone $summaryBase)->whereIn('status', ['draft', 'returned_for_correction'])->count(),
                'awaiting_response' => GrievanceInformationRequest::query()->where('status', 'open')->where('requested_from_type', 'employee')
                    ->whereIn('grievance_id', (clone $summaryBase)->select('id'))->count(),
                'decision_issued' => (clone $summaryBase)->where('status', 'decision_issued')->count(),
                'next_appeal_deadline' => (clone $summaryBase)->where('status', 'decision_issued')->whereNotNull('appeal_deadline_at')
                    ->where('appeal_deadline_at', '>', now())->min('appeal_deadline_at'),
            ],
            'can' => ['create' => $user->can('grievances.create') && $this->settings->enabled()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('grievances.create'), 403);

        return Inertia::render('Grievances/Portal/Form', [
            'grievance' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request, GrievanceEvidenceService $evidence): RedirectResponse
    {
        $data = $this->validateForm($request);
        $user = $request->user();

        $grievance = DB::transaction(function () use ($data, $request, $user, $evidence): Grievance {
            $grievance = $this->cases->createDraft($user, $data);
            $this->attachFiles($request, $grievance, $evidence);

            return $grievance;
        });
        if ($request->boolean('submit')) {
            $this->assertApplicationComplete($grievance->refresh());
            $grievance = $this->cases->submit($grievance, $user);
        }

        return to_route('employee.grievances.show', $grievance)->with('flash', ['message' => __($request->boolean('submit') ? 'grievances.flash.submitted' : 'grievances.flash.draft_saved'), 'type' => 'success']);
    }

    public function show(Request $request, Grievance $grievance, GrievanceAppealService $appeals): Response
    {
        $user = $request->user();
        abort_unless($this->access->isComplainant($user, $grievance) && $user->can('grievances.view_own'), 404);

        $eligibility = $appeals->eligibility($grievance);

        return Inertia::render('Grievances/Portal/Show', [
            'grievance' => $this->presenter->forComplainant($grievance, $user),
            'reasonCodes' => $this->reasonCodes(GrievanceReasonCodeType::Withdrawal),
            'evidenceTypes' => GrievanceEvidenceType::values(),
            'can' => [
                'edit' => $grievance->status->isEditableByComplainant() && $user->can('grievances.update_draft'),
                'delete' => $grievance->status === GrievanceStatus::Draft && $grievance->submitted_at === null && $user->can('grievances.update_draft'),
                'submit' => $grievance->status->isEditableByComplainant() && $user->can('grievances.submit'),
                'withdraw' => ! $grievance->isFinal() && ! in_array($grievance->status, [GrievanceStatus::Draft, GrievanceStatus::WithdrawRequested], true) && $user->can('grievances.withdraw'),
                'upload' => ! $grievance->isFinal() && ($user->can('grievances.update_draft') || $user->can('grievances.submit')),
                'appeal' => $eligibility['allowed'] && $user->can('grievances.submit'),
                'accept_outcome' => $grievance->status === GrievanceStatus::DecisionIssued,
            ],
            'appeal' => ['allowed' => $eligibility['allowed'], 'reason' => $eligibility['reason'] ? __($eligibility['reason']) : null, 'deadline' => $eligibility['deadline']],
        ]);
    }

    public function edit(Request $request, Grievance $grievance): Response
    {
        $user = $request->user();
        abort_unless($this->access->isComplainant($user, $grievance) && $user->can('grievances.update_draft') && $grievance->status->isEditableByComplainant(), 403);

        return Inertia::render('Grievances/Portal/Form', [
            'grievance' => [
                'id' => $grievance->getKey(),
                'reference_number' => $grievance->reference_number,
                'status' => $grievance->status->value,
                'subject' => $grievance->subject,
                'description' => $grievance->description,
                'category_id' => $grievance->category_id,
                'incident_date' => $grievance->incident_date?->toDateString(),
                'respondent_description' => $grievance->respondent_description,
                'written_issue_raised_at' => $grievance->written_issue_raised_at?->toDateString(),
                'immediate_supervisor_name' => $grievance->immediate_supervisor_name,
                'applicant_address' => $grievance->applicant_address,
                'requested_remedy' => $grievance->requested_remedy,
                'late_submission_reason' => $grievance->late_submission_reason,
                'cause_ended_at' => $grievance->cause_ended_at?->toDateString(),
                'intake_reason_code' => $grievance->intake_reason_code,
                'intake_notes' => $grievance->intake_notes,
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, Grievance $grievance, GrievanceEvidenceService $evidence): RedirectResponse
    {
        $data = $this->validateForm($request, amendment: $grievance->status !== GrievanceStatus::Draft);
        $user = $request->user();

        DB::transaction(function () use ($grievance, $user, $data, $request, $evidence): void {
            $this->cases->updateDraft($grievance, $user, $data);
            $this->attachFiles($request, $grievance->refresh(), $evidence);
        });
        if ($request->boolean('submit')) {
            $this->assertApplicationComplete($grievance->refresh());
            $this->cases->submit($grievance->refresh(), $user);
        }

        return to_route('employee.grievances.show', $grievance)->with('flash', ['message' => __($request->boolean('submit') ? 'grievances.flash.submitted' : 'grievances.flash.draft_saved'), 'type' => 'success']);
    }

    public function submit(Request $request, Grievance $grievance): RedirectResponse
    {
        abort_unless($this->access->isComplainant($request->user(), $grievance), 403);
        $this->assertApplicationComplete($grievance);
        $this->cases->submit($grievance, $request->user());

        return back()->with('flash', ['message' => __('grievances.flash.submitted'), 'type' => 'success']);
    }

    public function destroy(Request $request, Grievance $grievance): RedirectResponse
    {
        $this->cases->deleteDraft($grievance, $request->user());

        return to_route('employee.grievances.index')->with('flash', ['message' => __('grievances.flash.draft_deleted'), 'type' => 'success']);
    }

    public function withdraw(Request $request, Grievance $grievance): RedirectResponse
    {
        $data = $request->validate([
            'reason_code' => ['required', 'string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->cases->withdraw($grievance, $request->user(), $data['reason_code'], $data['reason'] ?? null);

        return back()->with('flash', ['message' => __('grievances.flash.withdrawal_recorded'), 'type' => 'success']);
    }

    public function acceptOutcome(Request $request, Grievance $grievance): RedirectResponse
    {
        $this->cases->acceptOutcome($grievance, $request->user());

        return back()->with('flash', ['message' => __('grievances.flash.closed'), 'type' => 'success']);
    }

    public function uploadEvidence(Request $request, Grievance $grievance, GrievanceEvidenceService $evidence): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$this->settings->evidenceMaxSizeKb()],
            'evidence_type' => ['required', 'in:'.implode(',', GrievanceEvidenceType::values())],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $evidence->upload($grievance, $request->file('file'), $data, $request->user(), asComplainant: true);

        return back()->with('flash', ['message' => __('grievances.flash.evidence_uploaded'), 'type' => 'success']);
    }

    public function respond(Request $request, Grievance $grievance, GrievanceInformationRequest $informationRequest, GrievanceInformationService $information): RedirectResponse
    {
        abort_unless($informationRequest->grievance_id === $grievance->getKey(), 404);
        $data = $request->validate([
            'response_text' => ['required', 'string', 'max:10000'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', 'max:'.$this->settings->evidenceMaxSizeKb()],
        ]);
        $information->respond($informationRequest, $request->user(), $data['response_text'], $request->file('files', []));

        return back()->with('flash', ['message' => __('grievances.flash.response_sent'), 'type' => 'success']);
    }

    public function appeal(Request $request, Grievance $grievance, GrievanceAppealService $appeals): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:10000'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => ['file', 'max:'.$this->settings->evidenceMaxSizeKb()],
        ]);
        $appeals->file($grievance, $request->user(), $data['reason'], $request->file('files', []));

        return back()->with('flash', ['message' => __('grievances.flash.appeal_filed'), 'type' => 'success']);
    }

    public function downloadLetter(Request $request, Grievance $grievance, GrievanceLetter $letter, GrievanceCorrespondenceService $letters): StreamedResponse
    {
        abort_unless($letter->grievance_id === $grievance->getKey(), 404);

        return $letters->download($letter, $request->user());
    }

    public function downloadEvidence(Request $request, Grievance $grievance, string $evidenceId, GrievanceEvidenceService $evidence): StreamedResponse
    {
        $item = $grievance->evidence()->whereKey($evidenceId)->firstOrFail();

        return $evidence->download($item, $request->user());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'categories' => GrievanceCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_am', 'description_en', 'description_am']),
            'evidenceTypes' => GrievanceEvidenceType::values(),
            'allowedExtensions' => ['pdf'],
            'maxFileKb' => self::FORM_FILE_MAX_KB,
        ];
    }

    /** @return array<string, mixed> */
    private function validateForm(Request $request, bool $amendment = false): array
    {
        // Sending needs the whole Art. 32 application; a draft may be saved incomplete.
        $sending = $request->boolean('submit') ? 'required' : 'nullable';
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => [RichTextContent::required(maxLength: 20000)],
            'category_id' => ['required', 'uuid', 'exists:grievance_categories,id'],
            'incident_date' => [$sending, 'nullable', 'date', 'before_or_equal:today'],
            'respondent_description' => ['nullable', 'string', 'max:2000'],
            // Art. 33(1): when the matter was presented in writing to the supervisor or manager, and to whom.
            'written_issue_raised_at' => [$this->writtenIssueRequired() ? $sending : 'nullable', 'nullable', 'date', 'before_or_equal:today'],
            'immediate_supervisor_name' => [$sending, 'nullable', 'string', 'max:255'],
            // Art. 32(2)(a), (g), (h): address, remedy sought, and the applicant's declaration.
            'applicant_address' => [$sending, 'nullable', 'string', 'max:500'],
            'requested_remedy' => [$sending, 'nullable', 'string', 'max:5000'],
            'declaration' => [$request->boolean('submit') ? 'accepted' : 'nullable'],
            // Art. 33(2): a late filing must state the force-majeure ground and when it ended.
            'late_submission_reason' => ['nullable', 'string', 'max:2000'],
            'cause_ended_at' => ['nullable', 'date', 'before_or_equal:today'],
            'amendment_reason' => [$amendment ? 'nullable' : 'prohibited', 'string', 'max:2000'],
            'submit' => ['nullable', 'boolean'],
            'files' => ['nullable', 'array', 'max:10'],
            // The application form takes PDF only, at most 2 MB each,
            // whatever the evidence settings allow elsewhere.
            'files.*' => ['file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.self::FORM_FILE_MAX_KB],
            'file_types' => ['nullable', 'array'],
            'file_types.*' => ['nullable', 'in:'.implode(',', GrievanceEvidenceType::values())],
        ]);
        if ($request->boolean('submit')) {
            $data['applicant_declared_at'] = now();
        }
        unset($data['declaration']);

        return $data;
    }

    private function writtenIssueRequired(): bool
    {
        return (bool) config('grievance.initial_resolution.require_before_formal', true);
    }

    /**
     * The stored application has everything Art. 32(2) and Art. 33(1) ask for
     * before it may be sent — also when a saved draft is sent on its own.
     */
    private function assertApplicationComplete(Grievance $grievance): void
    {
        $missing = [];
        foreach (['incident_date', 'immediate_supervisor_name', 'applicant_address', 'requested_remedy', 'applicant_declared_at'] as $field) {
            if (blank($grievance->{$field})) {
                $missing[$field] = __('grievances.errors.application_incomplete');
            }
        }
        if ($this->writtenIssueRequired() && $grievance->written_issue_raised_at === null) {
            $missing['written_issue_raised_at'] = __('grievances.errors.written_issue_required');
        }
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    private function attachFiles(Request $request, Grievance $grievance, GrievanceEvidenceService $evidence): void
    {
        foreach ($request->file('files', []) as $i => $file) {
            $evidence->upload($grievance, $file, [
                'evidence_type' => $request->input("file_types.$i") ?: 'document',
                'title' => $file->getClientOriginalName(),
            ], $request->user(), asComplainant: true);
        }
    }

    /** @return list<array{code: string, name_en: string, name_am: string|null}> */
    private function reasonCodes(GrievanceReasonCodeType $type): array
    {
        return GrievanceReasonCode::query()->where('type', $type->value)->where('is_active', true)->orderBy('sort_order')
            ->get(['code', 'name_en', 'name_am'])->toArray();
    }
}
