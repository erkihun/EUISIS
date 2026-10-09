<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\FieldWorkStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\FieldWork\ReviewFieldWorkRequest;
use App\Models\FieldWorkRequest;
use App\Services\FieldWork\FieldWorkService;
use App\Services\FieldWork\FieldWorkSupervisorResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FieldWorkController extends Controller
{
    public function __construct(private readonly FieldWorkService $service, private readonly FieldWorkSupervisorResolver $supervisors) {}

    public function pending(Request $request): Response
    {
        abort_unless($request->user()->canAny(['field_work.approve', 'field_work.return', 'field_work.reject']), 403);
        $rows = $this->supervisors
            ->applyApprovalScope(FieldWorkRequest::query(), $request->user())
            ->with(['requester:id,full_name,employee_number', 'fieldWorkType:id,name_en,name_am', 'destinationOrganization:id,name_en,name_am'])
            ->where('status', FieldWorkStatus::PendingSupervisorApproval->value)
            ->orderBy('submitted_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (FieldWorkRequest $row): array => [
                'id' => $row->id,
                'reference_number' => $row->reference_number,
                'employee' => $row->requester?->full_name,
                'employee_number' => $row->requester?->employee_number,
                'type_en' => $row->fieldWorkType?->name_en,
                'type_am' => $row->fieldWorkType?->name_am,
                'purpose' => $row->purpose,
                'destination_en' => $row->destinationOrganization?->name_en ?? $row->external_organization_name ?? $row->destination_location,
                'destination_am' => $row->destinationOrganization?->name_am ?? $row->external_organization_name ?? $row->destination_location,
                'starts_at' => $row->starts_at?->toIso8601String(),
                'expected_return_at' => $row->expected_return_at?->toIso8601String(),
            ]);

        return Inertia::render('FieldWork/Pending', [
            'requests' => $rows,
            'can' => [
                'approve' => $request->user()->can('field_work.approve'),
                'return' => $request->user()->can('field_work.return'),
                'reject' => $request->user()->can('field_work.reject'),
            ],
        ]);
    }

    public function approve(Request $request, FieldWorkRequest $fieldWork): RedirectResponse
    {
        $this->authorize('approve', $fieldWork);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:4000']]);
        $this->service->approve($request->user(), $fieldWork, $data['note'] ?? null);

        return back()->with('success', __('field-work.approved'));
    }

    public function returnForCorrection(ReviewFieldWorkRequest $request, FieldWorkRequest $fieldWork): RedirectResponse
    {
        $this->authorize('returnForCorrection', $fieldWork);
        $this->service->returnForCorrection($request->user(), $fieldWork, $request->validated('reason'));

        return back()->with('success', __('field-work.returned'));
    }

    public function reject(ReviewFieldWorkRequest $request, FieldWorkRequest $fieldWork): RedirectResponse
    {
        $this->authorize('reject', $fieldWork);
        $this->service->reject($request->user(), $fieldWork, $request->validated('reason'));

        return back()->with('success', __('field-work.rejected'));
    }
}
