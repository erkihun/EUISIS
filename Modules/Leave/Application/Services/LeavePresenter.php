<?php

declare(strict_types=1);

namespace Modules\Leave\Application\Services;

use App\Models\Employee;
use App\Models\User;
use Modules\Leave\Contracts\Data\LeaveStatus;
use Modules\Leave\Domain\Models\LeaveDocument;
use Modules\Leave\Domain\Models\LeaveRequest;
use Modules\Leave\Domain\Models\LeaveRequestEvent;

/**
 * Shapes a leave request for the pages. What the reader may not see (the
 * reason and the evidence of a sick or maternity leave) is left out here, on
 * the server, never hidden in the browser.
 */
class LeavePresenter
{
    public function __construct(
        private readonly LeaveAccess $access,
        private readonly LeaveCalendar $calendar,
        private readonly LeaveRequestService $requests,
    ) {}

    /** @return array<string, mixed> */
    public function row(LeaveRequest $request): array
    {
        $request->loadMissing(['employee', 'leaveType', 'organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am']);

        return [
            'id' => $request->id,
            'reference_number' => $request->reference_number,
            'employee' => $this->employee($request->employee),
            'leave_type' => $request->leaveType === null ? null : [
                'id' => $request->leaveType->id, 'code' => $request->leaveType->code, 'name_en' => $request->leaveType->name_en,
                'name_am' => $request->leaveType->name_am, 'category' => $request->leaveType->category->value, 'day_unit' => $request->leaveType->day_unit,
            ],
            'organization' => $request->organization === null ? null : ['id' => $request->organization->id, 'name_en' => $request->organization->name_en, 'name_am' => $request->organization->name_am],
            'unit' => $request->organizationUnit === null ? null : ['id' => $request->organizationUnit->id, 'name_en' => $request->organizationUnit->name_en, 'name_am' => $request->organizationUnit->name_am],
            'budget_year' => $request->budget_year,
            'starts_on' => $request->starts_on->toDateString(),
            'ends_on' => $request->ends_on->toDateString(),
            'is_half_day' => $request->is_half_day,
            'half_day_part' => $request->half_day_part,
            'days_counted' => $request->days_counted,
            'status' => $request->status->value,
            'registered_by_hr' => $request->registered_by_hr,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(LeaveRequest $request, User $viewer): array
    {
        $request->loadMissing(['supervisor:id,name', 'documents', 'events', 'leaveType']);
        $sensitive = $this->access->canSeeSensitive($viewer, $request);
        $today = $this->calendar->today();

        return [
            ...$this->row($request),
            'reason' => $sensitive ? $request->reason : null,
            'reason_hidden' => ! $sensitive && $request->reason !== null,
            'contact_during_leave' => $request->contact_during_leave,
            'pay_breakdown' => $request->pay_breakdown,
            'expected_delivery_on' => $sensitive ? $request->expected_delivery_on?->toDateString() : null,
            'actual_delivery_on' => $sensitive ? $request->actual_delivery_on?->toDateString() : null,
            'ended_early_on' => $request->ended_early_on?->toDateString(),
            'supervisor' => $request->supervisor === null ? null : ['name' => $request->supervisor->name],
            'supervisor_comment' => $request->supervisor_comment,
            'supervisor_decided_at' => $request->supervisor_decided_at?->toIso8601String(),
            'hr_comment' => $request->hr_comment,
            'hr_decided_at' => $request->hr_decided_at?->toIso8601String(),
            'cancel_reason' => $request->cancel_reason,
            'needs_document' => $request->leaveType !== null && $this->requests->needsDocument($request->leaveType, $request->ruleSet?->rules()->where('leave_type_id', $request->leave_type_id)->first(), (float) $request->days_counted),
            'documents' => $sensitive ? $request->documents->map(fn (LeaveDocument $document): array => [
                'id' => $document->id, 'name' => $document->original_name, 'size_bytes' => $document->size_bytes,
                'uploaded_at' => $document->created_at?->toIso8601String(),
            ])->all() : [],
            'documents_hidden' => ! $sensitive && $request->documents->isNotEmpty(),
            'events' => $request->events->map(fn (LeaveRequestEvent $event): array => [
                'id' => $event->id, 'action' => $event->action, 'actor' => $event->actor_name,
                // A comment may repeat the diagnosis: it follows the same rule as the reason.
                'comment' => $sensitive || ! in_array($event->action, ['submitted'], true) ? $event->comment : null,
                'data' => $event->data, 'at' => $event->created_at?->toIso8601String(),
            ])->all(),
            'can' => [
                'decide_as_supervisor' => $this->access->canDecideAsSupervisor($viewer, $request),
                'decide_as_hr' => $this->access->canDecideAsHr($viewer, $request),
                'cancel' => $this->access->canCancel($viewer, $request, $today),
                'end_early' => $this->access->canEndEarly($viewer, $request, $today),
                'record_delivery' => $request->status === LeaveStatus::Approved && $request->leaveType?->category->value === 'maternity'
                    && $request->actual_delivery_on === null && $this->access->canManage($viewer, $request),
                'add_document' => in_array($request->status, LeaveStatus::HOLDING, true)
                    && ($this->access->isOwner($viewer, $request) || $this->access->canManage($viewer, $request)),
            ],
            'is_owner' => $this->access->isOwner($viewer, $request),
        ];
    }

    /** @return array<string, mixed>|null */
    public function employee(?Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        return [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'name_en' => $employee->fullNameEn(),
            'name_am' => $employee->fullNameAm(),
        ];
    }
}
