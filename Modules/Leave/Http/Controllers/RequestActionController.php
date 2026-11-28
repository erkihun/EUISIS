<?php

declare(strict_types=1);

namespace Modules\Leave\Http\Controllers;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Leave\Application\Services\LeaveAccess;
use Modules\Leave\Application\Services\LeaveRequestService;
use Modules\Leave\Contracts\Data\LeaveStatus;
use Modules\Leave\Domain\Models\LeaveDocument;
use Modules\Leave\Domain\Models\LeaveRequest;
use Modules\Leave\Http\Support\StoresLeaveDocuments;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Decisions and changes on one request. The controller checks the shape of
 * the input; who may act, and whether the request is in a state that allows
 * it, is decided by LeaveRequestService and LeaveAccess.
 */
class RequestActionController extends Controller
{
    use StoresLeaveDocuments;

    public function __construct(
        private readonly LeaveRequestService $service,
        private readonly LeaveAccess $access,
    ) {}

    public function supervisorDecision(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $data = $this->decision($request);
        $this->service->decideAsSupervisor($request->user(), $leaveRequest, $data['decision'] === 'approve', $data['comment'] ?? null);

        return back()->with('success', __($data['decision'] === 'approve' ? 'leave::messages.flash.supervisor_approved' : 'leave::messages.flash.rejected'));
    }

    public function hrDecision(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $data = $this->decision($request);
        $this->service->decideAsHr($request->user(), $leaveRequest, $data['decision'] === 'approve', $data['comment'] ?? null);

        return back()->with('success', __($data['decision'] === 'approve' ? 'leave::messages.flash.approved' : 'leave::messages.flash.rejected'));
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $this->service->cancel($request->user(), $leaveRequest, $data['reason'] ?? null);

        return back()->with('success', __('leave::messages.flash.cancelled'));
    }

    public function endEarly(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $data = $request->validate([
            'last_day_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [], ['last_day_on' => __('leave::messages.fields.last_day_on'), 'reason' => __('leave::messages.fields.reason')]);
        $this->service->endEarly($request->user(), $leaveRequest, $data['last_day_on'], $data['reason']);

        return back()->with('success', __('leave::messages.flash.ended_early'));
    }

    public function delivery(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $data = $request->validate(['delivered_on' => ['required', 'date_format:Y-m-d']], [], ['delivered_on' => __('leave::messages.fields.delivered_on')]);
        $this->service->recordDelivery($request->user(), $leaveRequest, $data['delivered_on']);

        return back()->with('success', __('leave::messages.flash.delivery_recorded'));
    }

    public function addDocument(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $user = $request->user();
        abort_unless(
            in_array($leaveRequest->status, LeaveStatus::HOLDING, true)
            && ($this->access->isOwner($user, $leaveRequest) || $this->access->canManage($user, $leaveRequest)),
            403,
        );
        $request->validate([
            'documents' => ['required', 'array', 'min:1', 'max:5'],
            'documents.*' => ['file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ], [], ['documents' => __('leave::messages.fields.documents'), 'documents.*' => __('leave::messages.fields.documents')]);
        $this->storeDocuments($this->service, $user, $leaveRequest, $request->file('documents'));

        return back()->with('success', __('leave::messages.flash.document_added'));
    }

    public function download(Request $request, LeaveDocument $leaveDocument, WriteAuditLogAction $audit): StreamedResponse
    {
        $user = $request->user();
        $leaveRequest = $leaveDocument->request()->firstOrFail();
        abort_unless($this->access->canView($user, $leaveRequest) && $this->access->canSeeSensitive($user, $leaveRequest), 403);
        abort_unless(Storage::disk($leaveDocument->disk)->exists($leaveDocument->path), 404);

        $audit->execute(AuditEventType::LeaveDocumentDownloaded, $user, $leaveRequest, $leaveRequest->organization_id, null, [
            'document_id' => $leaveDocument->id, 'reference_number' => $leaveRequest->reference_number,
        ], null, $request);

        return Storage::disk($leaveDocument->disk)->download($leaveDocument->path, $leaveDocument->original_name);
    }

    /** @return array{decision: string, comment?: ?string} */
    private function decision(Request $request): array
    {
        return $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            // A refusal always says why.
            'comment' => ['nullable', 'required_if:decision,reject', 'string', 'max:1000'],
        ], [], ['comment' => __('leave::messages.fields.comment')]);
    }
}
