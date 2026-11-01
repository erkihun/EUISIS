<?php

namespace Modules\Court\Http\Controllers\Applicant;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\ApplicantResponseReply;
use Modules\Court\Domain\Models\RespondentResponse;
use Modules\Court\Application\Services\ResponseNotificationService;
use Modules\Court\Application\Services\CaseParties;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ApplicantResponseReplyController extends Controller
{
    public function index()
    {
        $applicantId = auth('applicant')->id();
        abort_if(!$applicantId, 403);

        $replies = DB::table('court_applicant_response_replies as arr')
            ->join('court_cases as cc', 'cc.id', '=', 'arr.case_id')
            ->join('court_respondent_responses as rr', 'rr.id', '=', 'arr.respondent_response_id')
            ->leftJoin('court_respondents as r', 'r.id', '=', 'rr.respondent_id')
            ->select(
                'arr.id',
                'arr.case_id',
                'arr.respondent_response_id',
                'arr.description',
                'arr.review_status',
                'arr.review_note',
                'arr.created_at',
                'cc.case_number',
                'rr.response_number',
                'rr.title as respondent_response_title',
                'r.first_name',
                'r.middle_name',
                'r.last_name'
            )
            ->where('arr.applicant_id', $applicantId)
            ->orderByDesc('arr.created_at')
            ->paginate(15);

        // React page Court/Applicant/Replies/Index.
        return Inertia::render('Court/Applicant/Replies/Index', [
            'replies' => $replies->through(fn (object $reply): array => [
                'id' => (int) $reply->id,
                'case_id' => (int) $reply->case_id,
                'respondent_response_id' => (int) $reply->respondent_response_id,
                'case_number' => (string) $reply->case_number,
                'response_number' => !empty($reply->response_number) ? (string) $reply->response_number : null,
                'review_status' => (string) ($reply->review_status ?? 'awaiting_review'),
                'created_at' => $this->isoDate($reply->created_at ?? null),
                'editable' => ($reply->review_status ?? null) !== 'accepted',
            ]),
        ]);
    }

    public function create(int $caseId, int $responseId)
    {
        $applicantId = auth('applicant')->id();
        abort_if(!$applicantId, 403);

        [$case, $response] = $this->loadCaseAndResponse($caseId, $responseId, $applicantId);
        $applicant = auth('applicant')->user();

        return Inertia::render('Court/Applicant/Replies/Create', [
            'case' => $this->caseSummary($case),
            'response' => $this->responseSummary($response),
            'applicantName' => $this->joinName($applicant->first_name ?? null, $applicant->middle_name ?? null, $applicant->last_name ?? null),
        ]);
    }

    public function store(Request $request, int $caseId, int $responseId)
    {
        $applicantId = auth('applicant')->id();
        abort_if(!$applicantId, 403);

        [$case, $response] = $this->loadCaseAndResponse($caseId, $responseId, $applicantId);

        $data = $request->validate([
            'description' => ['required', 'string'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:2048'],
        ]);

        $path = app(\Modules\Court\Application\Services\SecureUploadService::class)->store(
            $request->file('pdf'),
            'applicant/response-replies',
            'court_private',
            ['related_type' => 'court_case', 'related_id' => $caseId, 'applicant_id' => auth('applicant')->id()]
        );

        $reply = ApplicantResponseReply::create([
            'case_id' => $case->id,
            'applicant_id' => $applicantId,
            'respondent_response_id' => $response->id,
            'description' => $data['description'],
            'pdf_path' => $path,
            'review_status' => 'awaiting_review',
            'review_note' => null,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
        ]);

        $respondentResponse = RespondentResponse::query()->findOrFail($response->id);
        ResponseNotificationService::notifyApplicantResponseReplyCreated($reply, $respondentResponse);

        return redirect()->route('court.applicant.cases.respondentResponses.replies.show', [$case->id, $response->id, $reply->id]);
    }

    public function show(int $caseId, int $responseId, int $replyId)
    {
        $applicantId = auth('applicant')->id();
        abort_if(!$applicantId, 403);

        [$case, $response] = $this->loadCaseAndResponse($caseId, $responseId, $applicantId);
        $reply = $this->loadReply($replyId, $case->id, $response->id, $applicantId);

        return Inertia::render('Court/Applicant/Replies/Show', [
            'case' => $this->caseSummary($case),
            'response' => $this->responseSummary($response),
            'reply' => $this->replySummary($reply),
        ]);
    }

    public function edit(int $caseId, int $responseId, int $replyId)
    {
        $applicantId = auth('applicant')->id();
        abort_if(!$applicantId, 403);

        [$case, $response] = $this->loadCaseAndResponse($caseId, $responseId, $applicantId);
        $reply = $this->loadReply($replyId, $case->id, $response->id, $applicantId);
        $this->ensureEditableReply($reply);

        return Inertia::render('Court/Applicant/Replies/Edit', [
            'case' => $this->caseSummary($case),
            'response' => $this->responseSummary($response),
            'reply' => $this->replySummary($reply),
        ]);
    }

    public function update(Request $request, int $caseId, int $responseId, int $replyId)
    {
        $applicantId = auth('applicant')->id();
        abort_if(!$applicantId, 403);

        [$case, $response] = $this->loadCaseAndResponse($caseId, $responseId, $applicantId);
        $reply = ApplicantResponseReply::query()
            ->whereKey($replyId)
            ->where('case_id', $case->id)
            ->where('respondent_response_id', $response->id)
            ->where('applicant_id', $applicantId)
            ->firstOrFail();
        $this->ensureEditableReply($reply);

        $data = $request->validate([
            'description' => ['required', 'string'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:2048'],
        ]);

        if ($request->hasFile('pdf')) {
            app(\Modules\Court\Application\Services\LegalHoldService::class)->assertFileMayBeDeleted((string) $reply->pdf_path);
            Storage::disk('court_private')->delete($reply->pdf_path);
            Storage::disk('court_public')->delete($reply->pdf_path);
            $reply->pdf_path = app(\Modules\Court\Application\Services\SecureUploadService::class)->store(
                $request->file('pdf'),
                'applicant/response-replies',
                'court_private',
                ['related_type' => 'court_case', 'related_id' => (int) $reply->case_id, 'applicant_id' => auth('applicant')->id()]
            );
        }

        $reply->description = $data['description'];
        $reply->review_status = 'awaiting_review';
        $reply->review_note = null;
        $reply->reviewed_by_user_id = null;
        $reply->reviewed_at = null;
        $reply->save();

        return redirect()->route('court.applicant.cases.respondentResponses.replies.show', [$case->id, $response->id, $reply->id]);
    }

    public function destroy(int $caseId, int $responseId, int $replyId)
    {
        $applicantId = auth('applicant')->id();
        abort_if(!$applicantId, 403);

        [$case, $response] = $this->loadCaseAndResponse($caseId, $responseId, $applicantId);

        $reply = ApplicantResponseReply::query()
            ->whereKey($replyId)
            ->where('case_id', $case->id)
            ->where('respondent_response_id', $response->id)
            ->where('applicant_id', $applicantId)
            ->firstOrFail();
        $this->ensureEditableReply($reply);

        app(\Modules\Court\Application\Services\LegalHoldService::class)->assertFileMayBeDeleted((string) $reply->pdf_path);
        Storage::disk('court_private')->delete($reply->pdf_path);
        Storage::disk('court_public')->delete($reply->pdf_path);
        $reply->delete();

        return redirect()->route('court.applicant.cases.respondentResponses.show', [$case->id, $response->id]);
    }

    private function loadCaseAndResponse(int $caseId, int $responseId, int $applicantId): array
    {
        $case = DB::table('court_cases as cc')
            ->leftJoin('court_case_types as ct', 'ct.id', '=', 'cc.case_type_id')
            ->select('cc.*', 'ct.name as case_type_name')
            ->where('cc.id', $caseId)
            ->whereIn('cc.id', CaseParties::caseIdsFor($applicantId))
            ->first();

        abort_if(!$case, 404);

        $response = DB::table('court_respondent_responses as rr')
            ->leftJoin('court_respondents as r', 'r.id', '=', 'rr.respondent_id')
            ->select(
                'rr.*',
                'r.first_name',
                'r.middle_name',
                'r.last_name',
                'r.email as respondent_email',
                'r.phone as respondent_phone',
                'r.organization_name as respondent_org'
            )
            ->where('rr.id', $responseId)
            ->where('rr.case_number', $case->case_number)
            ->where('rr.review_status', 'accepted')
            ->first();

        abort_if(!$response, 404);

        return [$case, $response];
    }

    private function loadReply(int $replyId, int $caseId, int $responseId, int $applicantId): object
    {
        $reply = DB::table('court_applicant_response_replies as arr')
            ->leftJoin('court_applicants as a', 'a.id', '=', 'arr.applicant_id')
            ->select(
                'arr.*',
                'a.first_name as applicant_first_name',
                'a.middle_name as applicant_middle_name',
                'a.last_name as applicant_last_name'
            )
            ->where('arr.id', $replyId)
            ->where('arr.case_id', $caseId)
            ->where('arr.respondent_response_id', $responseId)
            ->where('arr.applicant_id', $applicantId)
            ->first();

        abort_if(!$reply, 404);

        return $reply;
    }

    /** @return array{id: int, case_number: string} */
    private function caseSummary(object $case): array
    {
        return ['id' => (int) $case->id, 'case_number' => (string) $case->case_number];
    }

    /** The respondent response as the reply pages show it. @return array<string, mixed> */
    private function responseSummary(object $response): array
    {
        $name = $this->joinName($response->first_name ?? null, $response->middle_name ?? null, $response->last_name ?? null);

        return [
            'id' => (int) $response->id,
            'response_number' => !empty($response->response_number) ? (string) $response->response_number : null,
            'respondent_name' => $name !== '' ? $name : null,
            'has_pdf' => !empty($response->pdf_path),
        ];
    }

    /** @return array<string, mixed> */
    private function replySummary(object $reply): array
    {
        return [
            'id' => (int) $reply->id,
            'description' => (string) ($reply->description ?? ''),
            'review_status' => (string) ($reply->review_status ?? 'awaiting_review'),
            'review_note' => !empty($reply->review_note) ? (string) $reply->review_note : null,
            'created_at' => $this->isoDate($reply->created_at ?? null),
            'applicant_name' => $this->joinName($reply->applicant_first_name ?? null, $reply->applicant_middle_name ?? null, $reply->applicant_last_name ?? null),
            'editable' => ($reply->review_status ?? null) !== 'accepted',
            'has_pdf' => !empty($reply->pdf_path),
        ];
    }

    private function joinName(?string ...$parts): string
    {
        return collect($parts)->map(fn ($part) => trim((string) $part))->filter()->implode(' ');
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function ensureEditableReply(object $reply): void
    {
        abort_if(($reply->review_status ?? null) === 'accepted', 403, __('court::respondent.response_reply_locked'));
    }
}
