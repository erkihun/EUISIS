<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\CourtMs\CourtMsException;
use App\Services\CourtMs\EmployeeCourtPortal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * My Portal > My court cases > a case (court-ms Flow 6). The employee follows and
 * works on their own tribunal case here: its facts and review, hearings,
 * documents, messages with the court, respondent responses and their replies,
 * letters and decisions, and correcting a case review sent back. court-ms runs
 * its own rules for every step; nothing here stores the case.
 */
class EmployeeCourtCaseController extends Controller
{
    /** court-ms refusals the pages explain (courtCases.portal.errors.*); anything else reads as "cannot be reached". */
    private const KNOWN_ERRORS = ['not_linked', 'account_inactive', 'forbidden', 'not_found', 'refused'];

    public function __construct(private readonly EmployeeCourtPortal $portal) {}

    public function show(Request $request, int $case): Response|RedirectResponse
    {
        $employee = $this->employee($request);

        try {
            $data = $this->portal->workspace($employee, "cases/{$case}/workspace");
        } catch (CourtMsException $e) {
            return $this->failed($e, route('employee.court-cases'));
        }

        return Inertia::render('Employee/CourtCaseShow', [
            'workspace' => $data,
            'error' => $request->session()->get('court_error'),
            'done' => $request->session()->get('court_done'),
        ]);
    }

    public function message(Request $request, int $case): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        return $this->act($request, "cases/{$case}/messages", $data, success: 'messageSent');
    }

    public function upload(Request $request, int $case): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx', 'max:4096'],
        ]);

        return $this->act($request, "cases/{$case}/files", ['label' => $data['label'] ?? null], ['file' => $this->filePart($data['file'])], 'fileUploaded');
    }

    public function deleteFile(Request $request, int $case, int $file): RedirectResponse
    {
        return $this->act($request, "cases/{$case}/files/{$file}/delete", success: 'fileRemoved');
    }

    public function deleteEvidence(Request $request, int $case, int $evidence): RedirectResponse
    {
        return $this->act($request, "cases/{$case}/evidences/{$evidence}/delete", success: 'fileRemoved');
    }

    public function deleteWitness(Request $request, int $case, int $witness): RedirectResponse
    {
        return $this->act($request, "cases/{$case}/witnesses/{$witness}/delete", success: 'witnessRemoved');
    }

    /**
     * The employee asks for the record of a closed case to take to the appellate court
     * (ADR 0013 #14); the court's registry releases it, and it is then downloaded here.
     */
    public function appeal(Request $request, int $case): RedirectResponse
    {
        $data = $request->validate(['grounds' => ['required', 'string', 'max:10000']]);

        return $this->act($request, "cases/{$case}/appeal", $data, success: 'appealRequested');
    }

    /**
     * A case file, as court-ms serves it to the parties; kind is evidence, file, response, reply,
     * decision, letter, receipt, hearing, or appeal_record (once the registry released it).
     */
    public function download(Request $request, int $case, string $kind, ?int $id = null): SymfonyResponse|RedirectResponse
    {
        abort_unless(in_array($kind, ['evidence', 'file', 'response', 'reply', 'decision', 'letter', 'receipt', 'hearing', 'appeal_record'], true), 404);
        $employee = $this->employee($request);
        $responseId = $request->integer('response') ?: null;

        try {
            $file = $this->portal->file($employee, $case, $kind, $id, $responseId);
        } catch (CourtMsException $e) {
            return $this->failed($e, route('employee.court-cases.show', $case));
        }

        return response($file['body'], 200, [
            'Content-Type' => $file['type'],
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]+/', '_', $file['name']).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Correcting a case review sent back: the court-ms form's values and options. */
    public function edit(Request $request, int $case): Response|RedirectResponse
    {
        $employee = $this->employee($request);

        try {
            $form = $this->portal->workspace($employee, "cases/{$case}/edit");
        } catch (CourtMsException $e) {
            return $this->failed($e, route('employee.court-cases.show', $case));
        }
        if (! (bool) ($form['editable'] ?? false)) {
            return to_route('employee.court-cases.show', $case);
        }

        return Inertia::render('Employee/CourtCaseCorrect', ['form' => $form]);
    }

    public function correct(Request $request, int $case): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:20000'],
            'relief_requested' => ['required', 'string', 'max:5000'],
            'certify_appeal' => ['accepted'],
            'certify_evidence' => ['accepted'],
            'evidence_titles' => ['nullable', 'array', 'max:10'],
            'evidence_titles.*' => ['nullable', 'string', 'max:255'],
            'evidence_files' => ['nullable', 'array', 'max:10'],
            'evidence_files.*' => ['file', 'mimes:pdf', 'max:5120'],
            'witnesses' => ['nullable', 'array', 'max:20'],
            'witnesses.*.full_name' => ['nullable', 'string', 'max:255'],
            'witnesses.*.phone' => ['nullable', 'string', 'max:60'],
            'witnesses.*.email' => ['nullable', 'email', 'max:150'],
            'witnesses.*.address' => ['nullable', 'string', 'max:255'],
        ]);
        $employee = $this->employee($request);

        // The fields the employee does not change here travel as court-ms has them.
        try {
            $current = (array) ($this->portal->workspace($employee, "cases/{$case}/edit")['case'] ?? []);
        } catch (CourtMsException $e) {
            return $this->failed($e, route('employee.court-cases.show', $case));
        }

        $files = [];
        foreach (array_values((array) $request->file('evidence_files', [])) as $i => $file) {
            $files["evidence_files[{$i}]"] = $this->filePart($file);
        }

        return $this->act($request, "cases/{$case}/correct", [
            'title' => $current['title'] ?? '',
            'case_type_id' => $current['case_type_id'] ?? null,
            'filing_date' => $current['filing_date'] ?? now()->toDateString(),
            'respondent_name' => $current['respondent_name'] ?? null,
            'respondent_address' => $current['respondent_address'] ?? null,
            'description' => $data['description'],
            'relief_requested' => $data['relief_requested'],
            'certify_appeal' => '1',
            'certify_evidence' => '1',
            'evidence_titles' => array_values((array) ($data['evidence_titles'] ?? [])),
            'witnesses' => array_values(array_filter((array) ($data['witnesses'] ?? []), fn ($w): bool => filled($w['full_name'] ?? null))),
        ], $files, 'caseCorrected', route('employee.court-cases.show', $case));
    }

    /** A respondent response to the case, with the employee's replies and the reply form; `?edit={reply}` opens one reply for changes. */
    public function response(Request $request, int $case, int $response): Response|RedirectResponse
    {
        $employee = $this->employee($request);
        $edit = $request->integer('edit') ?: null;

        try {
            $data = $this->portal->workspace($employee, "cases/{$case}/responses/{$response}");
            $editing = $edit !== null ? ($this->portal->workspace($employee, "cases/{$case}/responses/{$response}/replies/{$edit}")['reply'] ?? null) : null;
        } catch (CourtMsException $e) {
            return $this->failed($e, route('employee.court-cases.show', $case));
        }

        return Inertia::render('Employee/CourtCaseResponse', [
            'page' => $data,
            'editing' => is_array($editing) && (bool) ($editing['editable'] ?? false) ? $editing : null,
            'error' => $request->session()->get('court_error'),
            'done' => $request->session()->get('court_done'),
        ]);
    }

    public function storeReply(Request $request, int $case, int $response): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:20000'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:2048'],
        ]);

        return $this->act($request, "cases/{$case}/responses/{$response}/replies", ['description' => $data['description']], ['pdf' => $this->filePart($data['pdf'])], 'replySent');
    }

    public function updateReply(Request $request, int $case, int $response, int $reply): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:20000'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:2048'],
        ]);
        $files = isset($data['pdf']) ? ['pdf' => $this->filePart($data['pdf'])] : [];

        return $this->act($request, "cases/{$case}/responses/{$response}/replies/{$reply}/update", ['description' => $data['description']], $files, 'replyUpdated',
            route('employee.court-cases.responses.show', [$case, $response]));
    }

    public function destroyReply(Request $request, int $case, int $response, int $reply): RedirectResponse
    {
        return $this->act($request, "cases/{$case}/responses/{$response}/replies/{$reply}/delete", success: 'replyRemoved');
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()?->employee;
        abort_if($employee === null || ! $this->portal->enabled(), 404);

        return $employee;
    }

    /**
     * Sends one change to court-ms and comes back with its outcome: court-ms's own
     * validation errors land on the form fields, a refusal is explained once.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, array{name: string, contents: string, mime?: string}>  $files
     */
    private function act(Request $request, string $path, array $data = [], array $files = [], string $success = 'saved', ?string $redirect = null): RedirectResponse
    {
        $employee = $this->employee($request);

        try {
            $this->portal->workspace($employee, $path, $data, $files, changes: true);
        } catch (CourtMsException $e) {
            if ($e->status === 422) {
                throw ValidationException::withMessages($e->errors !== [] ? $e->errors : ['form' => [$e->getMessage()]]);
            }

            return back()->with('court_error', in_array($e->errorCode, self::KNOWN_ERRORS, true) ? $e->errorCode : 'unavailable');
        }

        $target = $redirect !== null ? redirect()->to($redirect) : back();

        return $target->with('court_done', $success);
    }

    private function failed(CourtMsException $e, string $fallback): RedirectResponse
    {
        abort_if($e->status === 404 && $e->errorCode === 'not_found', 404);

        return redirect()->to($fallback)->with('court_error', in_array($e->errorCode, self::KNOWN_ERRORS, true) ? $e->errorCode : 'unavailable');
    }

    /** @return array{name: string, contents: string, mime: string} */
    private function filePart(UploadedFile $file): array
    {
        return ['name' => $file->getClientOriginalName() ?: 'document', 'contents' => (string) $file->get(), 'mime' => $file->getMimeType() ?: 'application/octet-stream'];
    }
}
