<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\CaseInspectionFinding;
use Modules\Court\Domain\Models\CaseInspectionRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CaseInspectionFindingController extends Controller
{
    /** The severities, in the order the form offers them. */
    private const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    /**
     * Every finding this user may see (React, Court/Inspections/FindingsIndex).
     */
    public function index(): Response
    {
        $user = auth()->user();
        $query = CaseInspectionFinding::with(['request.case', 'recordedBy'])
            ->orderByDesc('finding_date')
            ->orderByDesc('id');

        if (!$this->canAccessAllFindings()) {
            $query->whereHas('request', function ($q) use ($user) {
                $q->where('assigned_inspector_user_id', $user?->id);
            });
        }

        $requestId = request()->integer('request_id');
        if ($requestId) {
            $query->where('case_inspection_request_id', $requestId);
        }

        $caseId = request()->integer('case_id');
        if ($caseId) {
            $query->whereHas('request', function ($q) use ($caseId) {
                $q->where('court_case_id', $caseId);
            });
        }

        $severity = trim((string) request('severity', ''));
        if ($severity !== '') {
            $query->where('severity', $severity);
        }

        $findings = $query->paginate(12)->withQueryString()
            ->through(fn (CaseInspectionFinding $finding): array => $this->findingRow($finding));

        return Inertia::render('Court/Inspections/FindingsIndex', [
            'findings' => $findings,
            'requests' => $this->requestOptions($caseId ?: null),
            'filters' => [
                'request_id' => $requestId ? (string) $requestId : '',
                'case_id' => $caseId ? (string) $caseId : '',
                'severity' => $severity,
            ],
            'severities' => self::SEVERITIES,
            'nav' => [
                'requests' => $user?->hasPermission('inspection-requests.manage') ?? false,
                'findings' => $user?->hasPermission('inspection-findings.manage') ?? false,
            ],
        ]);
    }

    /**
     * The new-finding form (React, Court/Inspections/FindingForm with a null finding).
     */
    public function create(): Response
    {
        $prefillRequestId = request()->integer('request_id');

        return Inertia::render('Court/Inspections/FindingForm', $this->formProps(null, $prefillRequestId));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'case_inspection_request_id' => ['required', 'exists:court_case_inspection_requests,id'],
            'finding_date' => ['required', 'date'],
            'title' => ['required', 'string', 'max:255'],
            'details' => ['required', 'string'],
            'severity' => ['required', 'in:low,medium,high,critical'],
            'attachment_pdf' => ['nullable', 'file', 'mimes:pdf', 'max:2048'],
        ]);

        $requestRecord = CaseInspectionRequest::findOrFail((int) $data['case_inspection_request_id']);
        if (!$this->canAccessAllFindings() && (int) ($requestRecord->assigned_inspector_user_id ?? 0) !== (int) $request->user()?->id) {
            return back()
                ->withErrors(['case_inspection_request_id' => __('court::case_inspections.findings.create_assigned_only')])
                ->withInput();
        }

        $attachmentPath = null;
        $attachmentOriginalName = null;
        if ($request->hasFile('attachment_pdf')) {
            $file = $request->file('attachment_pdf');
            $attachmentPath = app(\Modules\Court\Application\Services\SecureUploadService::class)->store(
                $file,
                'case-inspections/findings',
                'local',
                ['related_type' => 'case_inspection_finding', 'user_id' => $request->user()?->id]
            );
            $attachmentOriginalName = $file->getClientOriginalName();
        }

        CaseInspectionFinding::create([
            'case_inspection_request_id' => $data['case_inspection_request_id'],
            'finding_date' => $data['finding_date'],
            'title' => $data['title'],
            'details' => $data['details'],
            'attachment_path' => $attachmentPath,
            'attachment_original_name' => $attachmentOriginalName,
            'severity' => $data['severity'],
            'recorded_by_user_id' => $request->user()?->id,
            'created_by_user_id' => $request->user()?->id,
            'updated_by_user_id' => $request->user()?->id,
        ]);

        // Auto-complete request once at least one finding is submitted.
        $requestRecord->update([
            'status' => 'completed',
            'updated_by_user_id' => $request->user()?->id,
        ]);

        return redirect()->route('court.case-inspection-findings.index')->with('success', __('court::case_inspections.findings.created'));
    }

    /**
     * One finding (React, Court/Inspections/FindingShow).
     */
    public function show(CaseInspectionFinding $finding): Response
    {
        $this->authorizeFindingAccess($finding);
        $finding->load(['request.case', 'recordedBy', 'acceptedBy']);

        return Inertia::render('Court/Inspections/FindingShow', [
            'finding' => $this->findingRow($finding) + [
                'details' => $finding->details,
                'recorded_by_name' => $finding->recordedBy?->name,
                'accepted_at' => $finding->accepted_at ? $finding->accepted_at->toIso8601String() : null,
                'accepted_by_name' => $finding->acceptedBy?->name,
            ],
            'canEdit' => !$this->isAcceptedLocked($finding),
            'canAccept' => $this->canAccessAllFindings() && !$this->isAcceptedLocked($finding),
        ]);
    }

    /**
     * The edit form (React, Court/Inspections/FindingForm with the finding).
     */
    public function edit(CaseInspectionFinding $finding)
    {
        $this->authorizeFindingAccess($finding);
        if ($this->isAcceptedLocked($finding)) {
            return redirect()
                ->route('court.case-inspection-findings.show', $finding)
                ->with('error', __('court::case_inspections.findings.accepted_locked'));
        }

        return Inertia::render('Court/Inspections/FindingForm', $this->formProps($finding, 0));
    }

    public function update(Request $request, CaseInspectionFinding $finding)
    {
        $this->authorizeFindingAccess($finding);
        if ($this->isAcceptedLocked($finding)) {
            return redirect()
                ->route('court.case-inspection-findings.show', $finding)
                ->with('error', __('court::case_inspections.findings.accepted_locked'));
        }

        $data = $request->validate([
            'case_inspection_request_id' => ['required', 'exists:court_case_inspection_requests,id'],
            'finding_date' => ['required', 'date'],
            'title' => ['required', 'string', 'max:255'],
            'details' => ['required', 'string'],
            'severity' => ['required', 'in:low,medium,high,critical'],
            'attachment_pdf' => ['nullable', 'file', 'mimes:pdf', 'max:2048'],
        ]);

        $requestRecord = CaseInspectionRequest::findOrFail((int) $data['case_inspection_request_id']);
        if (!$this->canAccessAllFindings() && (int) ($requestRecord->assigned_inspector_user_id ?? 0) !== (int) $request->user()?->id) {
            return back()
                ->withErrors(['case_inspection_request_id' => __('court::case_inspections.findings.update_assigned_only')])
                ->withInput();
        }

        $attachmentPath = $finding->attachment_path;
        $attachmentOriginalName = $finding->attachment_original_name;
        if ($request->hasFile('attachment_pdf')) {
            if ($attachmentPath && Storage::disk('local')->exists($attachmentPath)) {
                app(\Modules\Court\Application\Services\LegalHoldService::class)->assertFileMayBeDeleted((string) $attachmentPath);
                Storage::disk('local')->delete($attachmentPath);
            }
            $file = $request->file('attachment_pdf');
            $attachmentPath = app(\Modules\Court\Application\Services\SecureUploadService::class)->store(
                $file,
                'case-inspections/findings',
                'local',
                ['related_type' => 'case_inspection_finding', 'related_id' => (int) $finding->id, 'user_id' => $request->user()?->id]
            );
            $attachmentOriginalName = $file->getClientOriginalName();
        }

        $finding->update([
            'case_inspection_request_id' => $data['case_inspection_request_id'],
            'finding_date' => $data['finding_date'],
            'title' => $data['title'],
            'details' => $data['details'],
            'attachment_path' => $attachmentPath,
            'attachment_original_name' => $attachmentOriginalName,
            'severity' => $data['severity'],
            'updated_by_user_id' => $request->user()?->id,
        ]);

        return redirect()->route('court.case-inspection-findings.index')->with('success', __('court::case_inspections.findings.updated'));
    }

    public function destroy(CaseInspectionFinding $finding)
    {
        $this->authorizeFindingAccess($finding);
        if ($this->isAcceptedLocked($finding)) {
            return redirect()
                ->route('court.case-inspection-findings.show', $finding)
                ->with('error', __('court::case_inspections.findings.accepted_locked'));
        }

        if ($finding->attachment_path && Storage::disk('local')->exists($finding->attachment_path)) {
            app(\Modules\Court\Application\Services\LegalHoldService::class)->assertFileMayBeDeleted((string) $finding->attachment_path);
            Storage::disk('local')->delete($finding->attachment_path);
        }

        $finding->delete();

        return redirect()->route('court.case-inspection-findings.index')->with('success', __('court::case_inspections.findings.deleted'));
    }

    public function accept(CaseInspectionFinding $finding)
    {
        $this->authorizeFindingAccess($finding);
        abort_unless($this->canAccessAllFindings(), 403);

        if ($finding->accepted_at) {
            return back()->with('success', __('court::case_inspections.findings.already_accepted'));
        }

        $finding->update([
            'accepted_at' => now(),
            'accepted_by_user_id' => auth()->id(),
            'updated_by_user_id' => auth()->id(),
        ]);

        return back()->with('success', __('court::case_inspections.findings.accepted_success'));
    }

    public function downloadAttachment(CaseInspectionFinding $finding)
    {
        $this->authorizeFindingAccess($finding);
        abort_if(!$finding->attachment_path, 404);
        abort_unless(Storage::disk('local')->exists($finding->attachment_path), 404);

        $filename = $finding->attachment_original_name ?: ('finding-' . $finding->id . '.pdf');

        return Storage::disk('local')->response(
            $finding->attachment_path,
            $filename,
            ['Content-Type' => 'application/pdf']
        );
    }

    /** The row of a finding as the list, the show page and the form read it. */
    private function findingRow(CaseInspectionFinding $finding): array
    {
        return [
            'id' => (int) $finding->id,
            'request_id' => (int) $finding->case_inspection_request_id,
            'request_subject' => $finding->request?->subject,
            'case_id' => $finding->request?->court_case_id !== null ? (int) $finding->request->court_case_id : null,
            'case_number' => $finding->request?->case?->case_number,
            'title' => $finding->title,
            'finding_date' => $finding->finding_date?->toDateString(),
            'severity' => (string) $finding->severity,
            'accepted' => $finding->accepted_at !== null,
            'has_attachment' => (bool) $finding->attachment_path,
            'attachment_name' => $finding->attachment_original_name ?: ($finding->attachment_path ? basename((string) $finding->attachment_path) : null),
        ];
    }

    /** The props of the create and edit form: the finding (null when new), the requests to choose from and the prefill. */
    private function formProps(?CaseInspectionFinding $finding, int $prefillRequestId): array
    {
        return [
            'finding' => $finding ? $this->findingRow($finding) + ['details' => $finding->details] : null,
            'requests' => $this->requestOptions(null),
            'prefill' => ['request_id' => $prefillRequestId ? (string) $prefillRequestId : ''],
            'severities' => self::SEVERITIES,
            'today' => now()->toDateString(),
        ];
    }

    /** The requests this user may record findings against, newest first, optionally one case's. */
    private function requestOptions(?int $caseId): array
    {
        $user = auth()->user();
        $requestsQuery = CaseInspectionRequest::with('case')
            ->orderByDesc('request_date')
            ->select(['id', 'court_case_id', 'subject', 'request_date']);

        if (!$this->canAccessAllFindings()) {
            $requestsQuery->where('assigned_inspector_user_id', $user?->id);
        }
        if ($caseId) {
            $requestsQuery->where('court_case_id', $caseId);
        }

        return $requestsQuery->get()
            ->map(fn (CaseInspectionRequest $request): array => [
                'id' => (int) $request->id,
                'case_number' => $request->case?->case_number,
                'subject' => $request->subject,
                'request_date' => $request->request_date?->toDateString(),
            ])
            ->all();
    }

    private function canAccessAllFindings(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    private function isAcceptedLocked(CaseInspectionFinding $finding): bool
    {
        return !is_null($finding->accepted_at);
    }

    private function authorizeFindingAccess(CaseInspectionFinding $finding): void
    {
        if ($this->canAccessAllFindings()) {
            return;
        }

        $assignedInspectorId = (int) ($finding->request?->assigned_inspector_user_id
            ?? CaseInspectionRequest::where('id', $finding->case_inspection_request_id)->value('assigned_inspector_user_id')
            ?? 0);

        abort_unless($assignedInspectorId === (int) auth()->id(), 403);
    }
}
