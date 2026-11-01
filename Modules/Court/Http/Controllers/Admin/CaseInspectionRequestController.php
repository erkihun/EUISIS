<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\CaseInspectionFinding;
use Modules\Court\Domain\Models\CaseInspectionRequest;
use Modules\Court\Domain\Models\CourtCase;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CaseInspectionRequestController extends Controller
{
    /** The request statuses, in the order the form offers them. */
    private const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    /**
     * Every inspection request this user may see (React, Court/Inspections/RequestsIndex).
     */
    public function index(): Response
    {
        $query = CaseInspectionRequest::with(['case', 'requestedBy', 'assignedInspector', 'createdBy'])
            ->orderByDesc('request_date')
            ->orderByDesc('id');

        $user = auth()->user();
        $canAssign = $user?->hasPermission('assign.inspections') ?? false;
        $canManage = $user?->hasPermission('inspection-requests.manage') ?? false;

        if (!$canAssign && !$canManage) {
            $query->where(function ($q) use ($user) {
                $q->where('assigned_inspector_user_id', $user?->id)
                    ->orWhere('requested_by_user_id', $user?->id);
            });
        }

        $caseId = request()->integer('case_id');
        if ($caseId) {
            $query->where('court_case_id', $caseId);
        }

        $status = trim((string) request('status', ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $requests = $query->paginate(12)->withQueryString()
            ->through(fn (CaseInspectionRequest $request): array => $this->requestRow($request));

        return Inertia::render('Court/Inspections/RequestsIndex', [
            'requests' => $requests,
            'cases' => $this->caseOptions(),
            'filters' => ['case_id' => $caseId ? (string) $caseId : '', 'status' => $status],
            'statuses' => self::STATUSES,
            'nav' => [
                'requests' => $canManage,
                'findings' => $user?->hasPermission('inspection-findings.manage') ?? false,
            ],
        ]);
    }

    /**
     * The new-request form (React, Court/Inspections/RequestForm with a null request).
     */
    public function create(): Response
    {
        $prefillCaseId = request()->integer('case_id');
        $prefillInspectorId = request()->integer('assigned_inspector_user_id');

        return Inertia::render('Court/Inspections/RequestForm', $this->formProps(null, $prefillCaseId, $prefillInspectorId));
    }

    public function store(Request $request)
    {
        $rules = [
            'court_case_id' => ['required', 'exists:court_cases,id'],
            'request_date' => ['required', 'date'],
            'subject' => ['required', 'string', 'max:255'],
            'request_note' => ['nullable', 'string'],
            'status' => ['required', 'in:pending,in_progress,completed,cancelled'],
        ];

        if ($request->user()?->hasPermission('assign.inspections')) {
            $rules['assigned_inspector_user_id'] = ['nullable', 'exists:court_staff,id'];
        }

        $data = $request->validate($rules);

        CaseInspectionRequest::create([
            'court_case_id' => $data['court_case_id'],
            'request_date' => $data['request_date'],
            'subject' => $data['subject'],
            'request_note' => $data['request_note'] ?? null,
            'status' => $data['status'],
            'requested_by_user_id' => $request->user()?->id,
            'assigned_inspector_user_id' => $request->user()?->hasPermission('assign.inspections')
                ? ($data['assigned_inspector_user_id'] ?? null)
                : $request->user()?->id,
            'created_by_user_id' => $request->user()?->id,
            'updated_by_user_id' => $request->user()?->id,
        ]);

        return redirect()->route('court.case-inspection-requests.index')->with('success', __('court::case_inspections.requests.created'));
    }

    /**
     * One request with its findings (React, Court/Inspections/RequestShow).
     */
    public function show(CaseInspectionRequest $requestRecord): Response
    {
        $requestRecord->load(['case', 'requestedBy', 'assignedInspector', 'createdBy', 'findings.recordedBy']);

        return Inertia::render('Court/Inspections/RequestShow', [
            'request' => $this->requestRow($requestRecord) + [
                'request_note' => $requestRecord->request_note,
                'requested_by_name' => $requestRecord->requestedBy?->name,
                'findings' => $requestRecord->findings
                    ->sortByDesc('finding_date')
                    ->values()
                    ->map(fn (CaseInspectionFinding $finding): array => [
                        'id' => (int) $finding->id,
                        'title' => $finding->title,
                        'finding_date' => $finding->finding_date?->toDateString(),
                        'severity' => (string) $finding->severity,
                        'recorded_by_name' => $finding->recordedBy?->name,
                    ])
                    ->all(),
            ],
            'canEdit' => $requestRecord->status !== 'completed',
        ]);
    }

    /**
     * The edit form (React, Court/Inspections/RequestForm with the request).
     */
    public function edit(CaseInspectionRequest $requestRecord)
    {
        if ($requestRecord->status === 'completed') {
            return redirect()
                ->route('court.case-inspection-requests.show', $requestRecord)
                ->with('error', __('court::case_inspections.requests.completed_locked'));
        }

        return Inertia::render('Court/Inspections/RequestForm', $this->formProps($requestRecord, 0, 0));
    }

    public function update(Request $request, CaseInspectionRequest $requestRecord)
    {
        if ($requestRecord->status === 'completed') {
            return redirect()
                ->route('court.case-inspection-requests.show', $requestRecord)
                ->with('error', __('court::case_inspections.requests.completed_locked'));
        }

        $rules = [
            'court_case_id' => ['required', 'exists:court_cases,id'],
            'request_date' => ['required', 'date'],
            'subject' => ['required', 'string', 'max:255'],
            'request_note' => ['nullable', 'string'],
            'status' => ['required', 'in:pending,in_progress,completed,cancelled'],
        ];

        if ($request->user()?->hasPermission('assign.inspections')) {
            $rules['assigned_inspector_user_id'] = ['nullable', 'exists:court_staff,id'];
        }

        $data = $request->validate($rules);

        $requestRecord->update([
            'court_case_id' => $data['court_case_id'],
            'request_date' => $data['request_date'],
            'subject' => $data['subject'],
            'request_note' => $data['request_note'] ?? null,
            'status' => $data['status'],
            'assigned_inspector_user_id' => $request->user()?->hasPermission('assign.inspections')
                ? ($data['assigned_inspector_user_id'] ?? null)
                : ($requestRecord->assigned_inspector_user_id ?? $request->user()?->id),
            'updated_by_user_id' => $request->user()?->id,
        ]);

        return redirect()->route('court.case-inspection-requests.index')->with('success', __('court::case_inspections.requests.updated'));
    }

    public function destroy(CaseInspectionRequest $requestRecord)
    {
        if ($requestRecord->status === 'completed') {
            return redirect()
                ->route('court.case-inspection-requests.show', $requestRecord)
                ->with('error', __('court::case_inspections.requests.completed_locked'));
        }

        $requestRecord->delete();

        return redirect()->route('court.case-inspection-requests.index')->with('success', __('court::case_inspections.requests.deleted'));
    }

    /** The row of a request as the list, the show page and the form read it. */
    private function requestRow(CaseInspectionRequest $request): array
    {
        return [
            'id' => (int) $request->id,
            'case_id' => $request->court_case_id !== null ? (int) $request->court_case_id : null,
            'case_number' => $request->case?->case_number,
            'case_title' => $request->case?->title,
            'subject' => $request->subject,
            'request_date' => $request->request_date?->toDateString(),
            'status' => (string) $request->status,
            'assigned_inspector_user_id' => $request->assigned_inspector_user_id !== null ? (int) $request->assigned_inspector_user_id : null,
            'assigned_inspector_name' => $request->assignedInspector?->name,
            'created_by_name' => $request->createdBy?->name,
        ];
    }

    /** The props of the create and edit form: the request (null when new), the choices and the prefills. */
    private function formProps(?CaseInspectionRequest $request, int $prefillCaseId, int $prefillInspectorId): array
    {
        $canAssign = auth()->user()?->hasPermission('assign.inspections') ?? false;

        return [
            'request' => $request ? $this->requestRow($request) + ['request_note' => $request->request_note] : null,
            'cases' => $this->caseOptions(),
            'inspectors' => $canAssign ? $this->inspectorsForAssign() : [],
            'canAssign' => $canAssign,
            'prefill' => [
                'case_id' => $prefillCaseId ? (string) $prefillCaseId : '',
                'assigned_inspector_user_id' => $prefillInspectorId ? (string) $prefillInspectorId : '',
            ],
            'statuses' => self::STATUSES,
            'today' => now()->toDateString(),
        ];
    }

    private function caseOptions(): array
    {
        return CourtCase::orderBy('case_number')->get(['id', 'case_number', 'title'])
            ->map(fn (CourtCase $case): array => ['id' => (int) $case->id, 'case_number' => (string) $case->case_number, 'title' => $case->title])
            ->all();
    }

    private function inspectorsForAssign(): array
    {
        return User::query()
            ->where('status', 'active')
            ->whereHas('roles.permissions', fn($q) => $q->where('name', 'inspection-findings.manage'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name])
            ->all();
    }
}
