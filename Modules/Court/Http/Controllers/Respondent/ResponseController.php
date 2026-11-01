<?php

namespace Modules\Court\Http\Controllers\Respondent;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\Respondent;
use Modules\Court\Domain\Models\RespondentResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Modules\Court\Application\Services\ResponseNotificationService;
use Inertia\Inertia;
use Modules\Court\Application\Support\Purifier;

class ResponseController extends Controller
{
    public function index(Request $request)
    {
        $respondentId = $this->currentRespondentId();
        Session::put('acting_as_respondent', true);

        $responses = RespondentResponse::where('respondent_id', $respondentId)
            ->orderByDesc('created_at')
            ->get();

        // The respondent's responses (React, Court/Respondent/Responses/Index).
        return Inertia::render('Court/Respondent/Responses/Index', [
            'responses' => $responses->map(fn (RespondentResponse $response): array => $this->present($response))->values()->all(),
        ]);
    }

    public function create(Request $request)
    {
        // The case number comes from the case page's "Give response" link and is not editable here.
        return Inertia::render('Court/Respondent/Responses/Form', [
            'response' => null,
            'caseNumber' => trim((string) $request->query('case_number', '')),
        ]);
    }

    public function store(Request $request)
    {
        $applicant = Auth::guard('applicant')->user();
        abort_unless($applicant, 403);
        $respondentId = $this->currentRespondentId();
        Session::put('acting_as_respondent', true);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'case_number' => ['required', 'string', 'max:64'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ]);

        $caseNumber = $this->normalizeCaseNumber($data['case_number'] ?? null);

        if ($caseNumber !== null) {
            $case = DB::table('court_cases')
                ->select(['id', 'status'])
                ->where('case_number', $caseNumber)
                ->first();
            if ($case && $case->status === 'closed') {
                return back()
                    ->withErrors(['case_number' => __('court::respondent.case_closed')])
                    ->withInput();
            }
        }

        $this->assertNotOwnCase($caseNumber, (int) $applicant->id);
        $this->assertCaseAuthorized($caseNumber, $respondentId, false);

        $path = app(\Modules\Court\Application\Services\SecureUploadService::class)->store(
            $request->file('pdf'),
            'respondent/responses',
            'court_private',
            ['related_type' => 'court_case', 'related_id' => (int) $case->id]
        );

        $descHtml = $this->cleanHtml($data['description'] ?? '');

        $response = DB::transaction(function () use ($respondentId, $caseNumber, $data, $path, $descHtml) {
            return RespondentResponse::create([
                'respondent_id' => $respondentId,
                'case_number' => $caseNumber,
                'response_number' => $this->nextResponseNumber($caseNumber),
                'title' => $data['title'],
                'description' => $descHtml ?: null,
                'pdf_path' => $path,
            ]);
        });

        ResponseNotificationService::notifyRespondentResponseCreated($response);

        return redirect()->route('court.respondent.responses.show', $response);
    }

    public function show(RespondentResponse $response)
    {
        $this->authorizeOwnership($response);

        return Inertia::render('Court/Respondent/Responses/Show', [
            'response' => $this->present($response, withDescription: true),
        ]);
    }

    public function edit(RespondentResponse $response)
    {
        $this->authorizeOwnership($response);
        if ($this->isLocked($response)) {
            return redirect()->route('court.respondent.responses.show', $response)->with('error', __('court::respondent.response_locked'));
        }

        return Inertia::render('Court/Respondent/Responses/Form', [
            'response' => $this->present($response, withDescription: true),
            'caseNumber' => (string) ($response->case_number ?? ''),
        ]);
    }

    /** One response as the pages show it; the stored PDF path stays on the server. @return array<string, mixed> */
    private function present(RespondentResponse $response, bool $withDescription = false): array
    {
        $reviewStatus = (string) ($response->review_status ?? 'awaiting_review') ?: 'awaiting_review';

        return [
            'id' => (int) $response->id,
            'title' => (string) $response->title,
            'case_number' => $response->case_number,
            'response_number' => $response->response_number,
            'review_status' => $reviewStatus,
            'review_note' => $response->review_note,
            // Accepted responses are read only, on the server too (update and destroy refuse them).
            'locked' => $this->isLocked($response),
            'has_pdf' => ! empty($response->pdf_path),
            'created_at' => $response->created_at?->toIso8601String(),
            // Purified on save; cleaned again on the way out, as the other rich text is.
            ...($withDescription ? ['description' => $response->description ? (string) \Modules\Court\Application\Support\Purifier::clean($response->description, 'cases') : null] : []),
        ];
    }

    public function update(Request $request, RespondentResponse $response)
    {
        $this->authorizeOwnership($response);
        $this->ensureEditable($response);
        $applicant = Auth::guard('applicant')->user();
        abort_unless($applicant, 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'case_number' => ['nullable', 'string', 'max:64'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
        ]);

        $caseNumber = $this->normalizeCaseNumber($data['case_number'] ?? null);

        $this->assertNotOwnCase($caseNumber, (int) $applicant->id);
        $this->assertCaseAuthorized($caseNumber, $this->currentRespondentId(), false);

        if ($request->hasFile('pdf')) {
            app(\Modules\Court\Application\Services\LegalHoldService::class)->assertFileMayBeDeleted((string) $response->pdf_path);
            Storage::disk('court_private')->delete($response->pdf_path);
            Storage::disk('court_public')->delete($response->pdf_path); // legacy cleanup
            $path = app(\Modules\Court\Application\Services\SecureUploadService::class)->store(
                $request->file('pdf'),
                'respondent/responses',
                'court_private',
                ['related_type' => 'respondent_response', 'related_id' => (int) $response->id]
            );
            $response->pdf_path = $path;
        }

        $refreshResponseNumber = $caseNumber !== $response->case_number
            || ($caseNumber !== null && empty($response->response_number));

        $descHtml = $this->cleanHtml($data['description'] ?? '');

        DB::transaction(function () use ($response, $caseNumber, $data, $refreshResponseNumber, $descHtml) {
            $response->fill([
                'case_number' => $caseNumber,
                'response_number' => $refreshResponseNumber
                    ? $this->nextResponseNumber($caseNumber, (int) $response->id)
                    : $response->response_number,
                'title' => $data['title'],
                'description' => $descHtml ?: null,
                // A changed response goes back to the court for review.
                'review_status' => 'awaiting_review',
                'review_note' => null,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
            ]);
            $response->save();
        });

        return redirect()->route('court.respondent.responses.show', $response);
    }

    public function destroy(RespondentResponse $response)
    {
        $this->authorizeOwnership($response);
        $this->ensureEditable($response);
        app(\Modules\Court\Application\Services\LegalHoldService::class)->assertFileMayBeDeleted((string) $response->pdf_path);
        Storage::disk('court_private')->delete($response->pdf_path);
        Storage::disk('court_public')->delete($response->pdf_path); // legacy cleanup
        $response->delete();

        return redirect()->route('court.respondent.responses.index');
    }

    /** Once the court accepts a response it is part of the case record and cannot change. */
    private function isLocked(RespondentResponse $response): bool
    {
        return in_array($response->review_status, ['accepted', 'accept'], true);
    }

    private function ensureEditable(RespondentResponse $response): void
    {
        abort_if($this->isLocked($response), 403, __('court::respondent.response_locked'));
    }

    private function authorizeOwnership(RespondentResponse $response): void
    {
        abort_if($response->respondent_id !== $this->currentRespondentId(), 403);
    }

    private function currentRespondentId(): int
    {
        // Respondent actions use the applicant guard while acting-as-respondent.
        $applicant = Auth::guard('applicant')->user();
        abort_unless($applicant, 403);

        $respondent = Respondent::where('email', $applicant->email)->first();
        if (!$respondent) {
            $phone = $applicant->phone ?? 'resp_' . substr(md5((string) microtime(true)), 0, 12);
            if (Respondent::where('phone', $phone)->where('email', '!=', $applicant->email)->exists()) {
                $phone = 'resp_' . substr(md5(uniqid('', true)), 0, 12);
            }

            $respondent = Respondent::create([
                'first_name'        => $applicant->first_name ?? '',
                'middle_name'       => $applicant->middle_name ?? '',
                'last_name'         => $applicant->last_name ?? '',
                'gender'            => $applicant->gender ?? null,
                'position'          => $applicant->position ?? '',
                'organization_name' => $applicant->organization_name ?? '',
                'address'           => $applicant->address ?? '',
                'national_id'       => $this->applicantNationalId($applicant),
                'phone'             => $phone,
                'email'             => $applicant->email,
                'password'          => \Illuminate\Support\Str::random(32),
            ]);
        } else {
            $dirty = false;
            $maybeNationalId = $this->applicantNationalId($applicant);
            if (!$respondent->national_id && $maybeNationalId) {
                $respondent->national_id = $maybeNationalId;
                $dirty = true;
            }
            if ($dirty) {
                $respondent->save();
            }
        }

        return (int) $respondent->id;
    }

    private function assertNotOwnCase(?string $caseNumber, int $applicantId): void
    {
        if (!$caseNumber) {
            return;
        }

        $case = DB::table('court_cases')
            ->select('applicant_id')
            ->where('case_number', $caseNumber)
            ->first();

        if ($case && (int) $case->applicant_id === $applicantId) {
            abort(403, __('court::respondent.cannot_respond_own_case'));
        }
    }

    private function assertCaseAuthorized(?string $caseNumber, int $respondentId, bool $api): void
    {
        if (!$caseNumber) {
            return;
        }

        $caseExists = DB::table('court_cases')->where('case_number', $caseNumber)->exists();
        if (!$caseExists) {
            $this->throwCaseNumberError(__('court::respondent.case_not_found'), $api);
        }

        $authorized = DB::table('court_respondent_case_views')
            ->where('respondent_id', $respondentId)
            ->where('case_number', $caseNumber)
            ->exists();

        if (!$authorized) {
            $this->throwCaseNumberError(__('court::respondent.case_not_authorized'), $api);
        }
    }

    private function throwCaseNumberError(string $message, bool $api): void
    {
        if ($api) {
            throw ValidationException::withMessages(['case_number' => [$message]]);
        }

        throw ValidationException::withMessages(['case_number' => [$message]]);
    }

    private function normalizeCaseNumber(?string $caseNumber): ?string
    {
        if ($caseNumber === null) {
            return null;
        }

        $normalized = trim($caseNumber);
        return $normalized === '' ? null : $normalized;
    }

    private function nextResponseNumber(?string $caseNumber, ?int $excludeResponseId = null): ?string
    {
        if ($caseNumber === null) {
            return null;
        }

        DB::table('court_cases')
            ->where('case_number', $caseNumber)
            ->lockForUpdate()
            ->first();

        return RespondentResponse::nextResponseNumberForCase($caseNumber, $excludeResponseId);
    }

    private function applicantNationalId($applicant): ?string
    {
        // Normalize to digits-only and trim to 16 characters to avoid DB length violations.
        $digits = preg_replace('/\D/', '', (string) ($applicant->getRawOriginal('national_id_number') ?? $applicant->national_id_number ?? ''));
        if ($digits === '') {
            return null;
        }
        return substr($digits, 0, 16);
    }

    private function cleanHtml(?string $html): string
    {
        $s = (string) ($html ?? '');
        if ($s === '') {
            return '';
        }
        if (str_contains($s, '&lt;') || str_contains($s, '&gt;')) {
            $s = htmlspecialchars_decode($s, ENT_QUOTES);
        }
        return \Modules\Court\Application\Support\Purifier::clean($s, 'cases');
    }
}
