<?php

declare(strict_types=1);

namespace Modules\Transfer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\OrganizationScope\OrganizationScopeService;
use Closure;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Transfer\Application\Actions\ApproveTransferApprovalAction;
use Modules\Transfer\Application\Actions\CompleteTransferAction;
use Modules\Transfer\Application\Actions\CreateTransferApplicationAction;
use Modules\Transfer\Application\Actions\RejectTransferApplicationAction;
use Modules\Transfer\Application\Actions\RejectTransferApprovalAction;
use Modules\Transfer\Application\Actions\ScreenTransferApplicationAction;
use Modules\Transfer\Application\Actions\SelectTransferCandidateAction;
use Modules\Transfer\Application\Actions\WithdrawTransferApplicationAction;
use Modules\Transfer\Domain\Models\TransferAnnouncement;
use Modules\Transfer\Domain\Models\TransferApplication;
use Modules\Transfer\Domain\Models\TransferApplicationDocument;
use Modules\Transfer\Domain\Models\TransferApproval;
use Modules\Transfer\Http\Requests\StoreTransferApplicationRequest;
use Modules\Transfer\Http\Resources\TransferApplicationResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransferApplicationController extends Controller
{
    public function index(Request $request, OrganizationScopeService $scopeService): Response
    {
        $this->authorize('viewAny', TransferApplication::class);

        $user = Auth::user();

        $scopeIds = $scopeService->accessibleOrganizationIds($user);
        $query = TransferApplication::query()
            ->with([
                'employee:id,employee_number,full_name,name_en',
                'announcement.position',
                'announcementPosition.position',
                'releasingOrganization',
                'receivingOrganization',
            ])
            ->when(! $scopeService->isUnrestricted($user), function ($query) use ($scopeIds): void {
                $query->where(function ($scoped) use ($scopeIds): void {
                    $scoped->whereIn('releasing_organization_id', $scopeIds)
                        ->orWhereIn('receiving_organization_id', $scopeIds);
                });
            })
            ->when($request->filled('announcement_id'), fn ($q) => $q->where('announcement_id', $request->string('announcement_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            // Grouped so the OR cannot escape the scope and status filters above.
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';
                $q->where(fn ($search) => $search
                    ->where('application_number', ci_like_operator(), $term)
                    ->orWhereHas('employee', fn ($employee) => $employee
                        ->where('employee_number', ci_like_operator(), $term)
                        ->orWhere('full_name', ci_like_operator(), $term)));
            })
            ->latest();

        $announcement = $request->filled('announcement_id')
            ? TransferAnnouncement::query()->with('position')->find($request->string('announcement_id')->toString())
            : null;

        return Inertia::render('Transfers/Applications/Index', [
            // The flat paginator shape ListPagination reads; a resource collection would nest it under meta.
            'applications' => $query->paginate(20)->withQueryString()
                ->through(fn (TransferApplication $application): array => TransferApplicationResource::make($application)->resolve()),
            'filters' => $request->only('status', 'announcement_id', 'search'),
            // Names the announcement a list was narrowed to, so the filter can be seen and cleared.
            'announcement' => $announcement === null ? null : [
                'id' => $announcement->id,
                'title_en' => $announcement->position?->title_en,
                'title_am' => $announcement->position?->title_am,
            ],
        ]);
    }

    public function show(TransferApplication $transferApplication): Response
    {
        $this->authorize('view', $transferApplication);

        $transferApplication->load([
            'employee:id,employee_number,full_name,name_en',
            'currentAssignment.position',
            'announcement.position',
            'announcementPosition.position',
            'releasingOrganization',
            'receivingOrganization',
            'documents.verifiedBy',
            'screeningReviews.reviewer',
            'approvals.approver',
        ]);

        $user = Auth::user();
        $can = fn (string $ability): bool => $user?->can($ability, $transferApplication) ?? false;

        return Inertia::render('Transfers/Applications/Show', [
            'application' => TransferApplicationResource::make($transferApplication)->resolve(),
            'can' => [
                'screen' => $can('screen'),
                'select' => $can('select'),
                'reject' => $can('reject'),
                'withdraw' => $can('withdraw'),
                'approveRelease' => $can('approveRelease'),
                'approveReceiving' => $can('approveReceiving'),
                'approveFinal' => $can('approveFinal'),
                'complete' => $can('complete'),
            ],
        ]);
    }

    /** An applicant's evidence, for the staff who screen and approve the application. */
    public function downloadDocument(TransferApplication $transferApplication, TransferApplicationDocument $document): StreamedResponse
    {
        $this->authorize('view', $transferApplication);
        abort_unless($document->transfer_application_id === $transferApplication->id, 404);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->original_name);
    }

    public function store(
        StoreTransferApplicationRequest $request,
        CreateTransferApplicationAction $action,
    ): RedirectResponse {
        $announcement = TransferAnnouncement::query()->findOrFail($request->validated()['announcement_id']);
        $employee = Employee::query()->findOrFail(Auth::user()->employee_id);

        try {
            $application = $action->execute($announcement, $employee, Auth::user(), $request->validated());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['announcement_id' => $e->getMessage()]);
        }

        return to_route('transfer-applications.show', $application)
            ->with('flash', ['message' => __('transfers.applicationSubmitted'), 'type' => 'success']);
    }

    public function screen(
        TransferApplication $transferApplication,
        ScreenTransferApplicationAction $action,
        Request $request,
    ): RedirectResponse {
        $this->authorize('screen', $transferApplication);

        if ($refused = $this->refusal(fn () => $action->execute($transferApplication, Auth::user(), $request->string('notes')->toString() ?: null))) {
            return $refused;
        }

        return back()->with('flash', ['message' => __('transfers.applicationScreened'), 'type' => 'success']);
    }

    public function select(
        TransferApplication $transferApplication,
        SelectTransferCandidateAction $action,
        Request $request,
    ): RedirectResponse {
        $this->authorize('select', $transferApplication);

        try {
            $request->validate(['effective_date' => ['nullable', 'date', 'after_or_equal:today']]);
            $action->execute(
                $transferApplication,
                Auth::user(),
                $request->string('notes')->toString() ?: null,
                $request->input('effective_date'),
            );
        } catch (DomainException $e) {
            // Posted from a button, not a form: a `status` field error has no field
            // to sit under on the page, so the refusal is a toast.
            return back()->with('flash', ['message' => $e->getMessage(), 'type' => 'error']);
        }

        return back()->with('flash', ['message' => __('transfers.applicationSelected'), 'type' => 'success']);
    }

    public function reject(
        TransferApplication $transferApplication,
        RejectTransferApplicationAction $action,
        Request $request,
    ): RedirectResponse {
        $this->authorize('reject', $transferApplication);

        $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        if ($refused = $this->refusal(fn () => $action->execute($transferApplication, Auth::user(), $request->string('reason')->trim()->toString()))) {
            return $refused;
        }

        return back()->with('flash', ['message' => __('transfers.applicationRejected'), 'type' => 'success']);
    }

    public function withdraw(TransferApplication $transferApplication, WithdrawTransferApplicationAction $action, Request $request): RedirectResponse
    {
        $this->authorize('withdraw', $transferApplication);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $action->execute($transferApplication, Auth::user(), $data['reason']);
        } catch (DomainException $e) {
            // The page posts from a dialog, not a form, so a validation error under
            // `status` had nowhere to render; the refusal is a toast.
            return back()->with('flash', ['message' => $e->getMessage(), 'type' => 'error']);
        }

        return back()->with('flash', ['message' => __('transfers.applicationWithdrawn'), 'type' => 'success']);
    }

    public function complete(
        TransferApplication $transferApplication,
        CompleteTransferAction $action,
    ): RedirectResponse {
        $this->authorize('complete', $transferApplication);

        try {
            $action->execute($transferApplication, Auth::user());
        } catch (DomainException $e) {
            return back()->with('flash', ['message' => $e->getMessage(), 'type' => 'error']);
        }

        return back()->with('flash', ['message' => __('transfers.transferCompleted'), 'type' => 'success']);
    }

    public function approveRelease(
        TransferApplication $transferApplication,
        ApproveTransferApprovalAction $action,
    ): RedirectResponse {
        $this->authorize('approveRelease', $transferApplication);

        $approval = TransferApproval::query()
            ->where('transfer_application_id', $transferApplication->id)
            ->where('approval_type', 'release')
            ->firstOrFail();

        if ($refused = $this->refusal(fn () => $action->execute($approval, Auth::user()))) {
            return $refused;
        }

        return back()->with('flash', ['message' => __('transfers.releaseApproved'), 'type' => 'success']);
    }

    public function rejectRelease(
        TransferApplication $transferApplication,
        RejectTransferApprovalAction $action,
        Request $request,
    ): RedirectResponse {
        $this->authorize('approveRelease', $transferApplication);

        $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $approval = TransferApproval::query()
            ->where('transfer_application_id', $transferApplication->id)
            ->where('approval_type', 'release')
            ->firstOrFail();

        if ($refused = $this->refusal(fn () => $action->execute($approval, Auth::user(), $request->string('reason')->trim()->toString()))) {
            return $refused;
        }

        return back()->with('flash', ['message' => __('transfers.releaseRejected'), 'type' => 'success']);
    }

    public function approveReceiving(
        TransferApplication $transferApplication,
        ApproveTransferApprovalAction $action,
    ): RedirectResponse {
        $this->authorize('approveReceiving', $transferApplication);

        $approval = TransferApproval::query()
            ->where('transfer_application_id', $transferApplication->id)
            ->where('approval_type', 'receiving')
            ->firstOrFail();

        if ($refused = $this->refusal(fn () => $action->execute($approval, Auth::user()))) {
            return $refused;
        }

        return back()->with('flash', ['message' => __('transfers.receivingApproved'), 'type' => 'success']);
    }

    public function rejectReceiving(
        TransferApplication $transferApplication,
        RejectTransferApprovalAction $action,
        Request $request,
    ): RedirectResponse {
        $this->authorize('approveReceiving', $transferApplication);

        $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $approval = TransferApproval::query()
            ->where('transfer_application_id', $transferApplication->id)
            ->where('approval_type', 'receiving')
            ->firstOrFail();

        if ($refused = $this->refusal(fn () => $action->execute($approval, Auth::user(), $request->string('reason')->trim()->toString()))) {
            return $refused;
        }

        return back()->with('flash', ['message' => __('transfers.receivingRejected'), 'type' => 'success']);
    }

    public function approveFinal(
        TransferApplication $transferApplication,
        ApproveTransferApprovalAction $action,
    ): RedirectResponse {
        $this->authorize('approveFinal', $transferApplication);

        $approval = TransferApproval::query()
            ->where('transfer_application_id', $transferApplication->id)
            ->where('approval_type', 'final')
            ->firstOrFail();

        if ($refused = $this->refusal(fn () => $action->execute($approval, Auth::user()))) {
            return $refused;
        }

        return back()->with('flash', ['message' => __('transfers.finalApproved'), 'type' => 'success']);
    }

    public function rejectFinal(
        TransferApplication $transferApplication,
        RejectTransferApprovalAction $action,
        Request $request,
    ): RedirectResponse {
        $this->authorize('approveFinal', $transferApplication);

        $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $approval = TransferApproval::query()
            ->where('transfer_application_id', $transferApplication->id)
            ->where('approval_type', 'final')
            ->firstOrFail();

        if ($refused = $this->refusal(fn () => $action->execute($approval, Auth::user(), $request->string('reason')->trim()->toString()))) {
            return $refused;
        }

        return back()->with('flash', ['message' => __('transfers.finalRejected'), 'type' => 'success']);
    }

    /**
     * Runs a workflow step and turns a refusal into a toast. These steps are
     * posted from buttons and dialogs, so a refusal has no field to sit under,
     * and an uncaught DomainException (a second click, a stale page after
     * someone else decided) used to end on the 500 page.
     */
    private function refusal(Closure $step): ?RedirectResponse
    {
        try {
            $step();
        } catch (DomainException $e) {
            return back()->with('flash', ['message' => $e->getMessage(), 'type' => 'error']);
        }

        return null;
    }
}
