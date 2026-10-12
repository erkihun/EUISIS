<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Http\Controllers\Controller;
use App\Models\EmployeeServiceFeedback;
use App\Models\EmployeeServiceFeedbackAttachment;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Permission-scoped inbox for the two non-rating public submission types. */
class ServiceSubmissionController extends Controller
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function suggestions(Request $request): Response
    {
        return $this->index($request, 'suggestion');
    }

    public function complaints(Request $request): Response
    {
        return $this->index($request, 'complaint');
    }

    private function index(Request $request, string $type): Response
    {
        $kind = $this->kind($type);
        $user = Auth::user();
        abort_unless($user->hasAnyPermission([$this->viewPermission($kind)]), 403);

        $query = $this->scoped($user, $kind);
        $status = $request->query('status');
        if (is_string($status) && in_array($status, array_column(ServiceFeedbackStatus::cases(), 'value'), true)) {
            $query->where('status', $status);
        }

        return Inertia::render('ServiceFeedback/SubmissionInbox', [
            'type' => $kind->value,
            'entries' => $query->with(['employee:id,full_name,employee_number', 'organization:id,name_en,name_am'])
                ->latest('created_at')->paginate(20)->withQueryString()
                ->through(fn (EmployeeServiceFeedback $entry): array => $this->summary($entry)),
            'counts' => $this->scoped($user, $kind)
                ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function show(string $type, EmployeeServiceFeedback $submission): Response
    {
        $kind = $this->kind($type);
        abort_unless($submission->type === $kind, 404);
        $this->assertAccess(Auth::user(), $submission, false);
        $this->authorize('view', $submission);

        $submission->load([
            'employee:id,full_name,employee_number',
            'organization:id,name_en,name_am',
            'organizationUnit:id,name_en,name_am',
            'position:id,title_en,title_am',
            'attachments:id,employee_service_feedback_id,original_name,mime_type,size_bytes,scan_status',
        ]);

        return Inertia::render('ServiceFeedback/SubmissionDetail', [
            'type' => $kind->value,
            'entry' => $this->summary($submission) + [
                'client_name' => $submission->client_name,
                'client_contact' => $submission->client_contact,
                'incident_date' => $submission->incident_date?->toDateString(),
                'incident_location' => $submission->incident_location,
                'organization_unit' => $submission->organizationUnit?->name_en,
                'position' => $submission->position?->title_en,
                'review_note' => $submission->review_note,
                'attachments' => $submission->attachments->map(fn ($file): array => [
                    'id' => $file->id,
                    'name' => $file->original_name,
                    'size' => $file->size_bytes,
                ])->all(),
            ],
            'canReview' => Auth::user()->hasAnyPermission([$kind === ServiceFeedbackType::Complaint
                ? 'service_feedback.complaints.review' : 'service_feedback.review'])
                && Auth::user()->can('review', $submission),
        ]);
    }

    public function review(Request $request, string $type, EmployeeServiceFeedback $submission): RedirectResponse
    {
        $kind = $this->kind($type);
        abort_unless($submission->type === $kind, 404);
        $this->assertAccess(Auth::user(), $submission, true);
        $this->authorize('review', $submission);

        $data = $request->validate([
            'status' => ['required', 'in:reviewed,resolved'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $submission->forceFill([
            'status' => ServiceFeedbackStatus::from($data['status']),
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ])->save();

        $this->audit->execute(
            eventType: $data['status'] === 'resolved' ? AuditEventType::ServiceFeedbackResolved : AuditEventType::ServiceFeedbackReviewed,
            actor: Auth::user(),
            auditable: $submission,
            organizationId: $submission->organization_id,
            reason: 'Public '.$kind->value.' marked '.$data['status'],
            request: $request,
        );

        return back();
    }

    public function download(Request $request, string $type, EmployeeServiceFeedback $submission, EmployeeServiceFeedbackAttachment $attachment): StreamedResponse
    {
        abort_unless($this->kind($type) === ServiceFeedbackType::Complaint
            && $submission->type === ServiceFeedbackType::Complaint
            && $attachment->employee_service_feedback_id === $submission->getKey(), 404);
        $this->assertAccess(Auth::user(), $submission, false);
        $this->authorize('view', $submission);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($attachment->path), 404);
        // Streamed through the disk so it also works on shared object storage.
        if (! \App\Support\Storage\StoredFileChecksum::matches('local', $attachment->path, $attachment->sha256)) {
            report(new \RuntimeException('Complaint attachment integrity mismatch: '.$attachment->getKey()));
            abort(409);
        }

        $this->audit->execute(
            eventType: AuditEventType::ServiceComplaintAttachmentDownloaded,
            actor: Auth::user(),
            auditable: $attachment,
            organizationId: $submission->organization_id,
            reason: 'Complaint attachment downloaded',
            request: $request,
        );

        return $disk->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function kind(string $type): ServiceFeedbackType
    {
        abort_unless(in_array($type, ['suggestion', 'complaint'], true), 404);

        return ServiceFeedbackType::from($type);
    }

    private function viewPermission(ServiceFeedbackType $type): string
    {
        return $type === ServiceFeedbackType::Complaint
            ? 'service_feedback.complaints.view' : 'service_feedback.view';
    }

    private function assertAccess(User $user, EmployeeServiceFeedback $entry, bool $review): void
    {
        $permission = $review
            ? ($entry->type === ServiceFeedbackType::Complaint ? 'service_feedback.complaints.review' : 'service_feedback.review')
            : $this->viewPermission($entry->type);

        abort_unless($user->hasAnyPermission([$permission])
            && (! $review || $user->hasAnyPermission([$this->viewPermission($entry->type)]))
            && ($user->employee_id === null || $user->employee_id !== $entry->employee_id)
            && ($this->scope->isUnrestricted($user)
                || ($entry->organization_id !== null && $this->scope->canAccessOrganization($user, $entry->organization_id))), 403);
    }

    /** @return Builder<EmployeeServiceFeedback> */
    private function scoped(User $user, ServiceFeedbackType $type): Builder
    {
        $query = EmployeeServiceFeedback::query()->where('type', $type->value);
        if ($user->employee_id !== null) {
            $query->where('employee_id', '!=', $user->employee_id);
        }
        if (! $this->scope->isUnrestricted($user)) {
            $query->whereIn('organization_id', $this->scope->accessibleOrganizationIds($user)->all());
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function summary(EmployeeServiceFeedback $entry): array
    {
        return [
            'id' => $entry->id,
            'reference' => $entry->reference_number,
            'type' => $entry->type->value,
            'status' => $entry->status->value,
            'message' => $entry->comment,
            'created_at' => $entry->created_at?->toIso8601String(),
            'employee' => $entry->employee?->full_name,
            'employee_number' => $entry->employee?->employee_number,
            'organization' => $entry->organization?->name_en,
        ];
    }
}
