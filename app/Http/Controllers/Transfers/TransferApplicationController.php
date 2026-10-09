<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Actions\Transfers\ApproveTransferApprovalAction;
use App\Actions\Transfers\CompleteTransferAction;
use App\Actions\Transfers\CreateTransferApplicationAction;
use App\Actions\Transfers\RejectTransferApplicationAction;
use App\Actions\Transfers\RejectTransferApprovalAction;
use App\Actions\Transfers\ScreenTransferApplicationAction;
use App\Actions\Transfers\SelectTransferCandidateAction;
use App\Actions\Transfers\WithdrawTransferApplicationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transfers\StoreTransferApplicationRequest;
use App\Models\Employee;
use App\Models\TransferAnnouncement;
use App\Models\TransferApplication;
use App\Models\TransferApproval;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransferApplicationController extends Controller
{
    public function index(Request $request, OrganizationScopeService $scopeService): Response
    {
        $this->authorize('viewAny', TransferApplication::class);

        $user = Auth::user();

        $scopeIds = $scopeService->accessibleOrganizationIds($user);
        $query = TransferApplication::query()
            ->with(['employee', 'announcement.position', 'releasingOrganization', 'receivingOrganization'])
            ->when(! $scopeService->isUnrestricted($user), function ($query) use ($scopeIds): void {
                $query->where(function ($scoped) use ($scopeIds): void {
                    $scoped->whereIn('releasing_organization_id', $scopeIds)
                        ->orWhereIn('receiving_organization_id', $scopeIds);
                });
            })
            ->when($request->filled('announcement_id'), fn ($q) => $q->where('announcement_id', $request->string('announcement_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest();

        return Inertia::render('Transfers/Applications/Index', [
            'applications' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only('status', 'announcement_id'),
        ]);
    }

    public function show(TransferApplication $transferApplication): Response
    {
        $this->authorize('view', $transferApplication);

        $transferApplication->load([
            'employee.currentAssignment.position',
            'announcement.organization',
            'announcement.position',
            'releasingOrganization',
            'receivingOrganization',
            'documents.verifiedBy',
            'screeningReviews.reviewer',
            'approvals.approver',
            'ruleOverrides.requestedBy',
            'ruleOverrides.approvedBy',
        ]);

        $user = Auth::user();

        return Inertia::render('Transfers/Applications/Show', [
            'application' => $transferApplication,
            'can' => [
                'screen' => $user?->can('screen', $transferApplication) ?? false,
                'select' => $user?->can('select', $transferApplication) ?? false,
                'reject' => $user?->can('reject', $transferApplication) ?? false,
                'withdraw' => $user?->can('withdraw', $transferApplication) ?? false,
                'approveRelease' => $user?->can('approveRelease', $transferApplication) ?? false,
                'approveReceiving' => $user?->can('approveReceiving', $transferApplication) ?? false,
                'approveFinal' => $user?->can('approveFinal', $transferApplication) ?? false,
                'complete' => $user?->can('complete', $transferApplication) ?? false,
            ],
        ]);
    }

    public function store(
        StoreTransferApplicationRequest $request,
        CreateTransferApplicationAction $action,
    ): RedirectResponse {
        $announcement = TransferAnnouncement::query()->findOrFail($request->validated()['announcement_id']);
        $employee = Employee::query()->findOrFail(Auth::user()->employee_id);

        try {
            $application = $action->execute($announcement, $employee, Auth::user(), $request->validated());
        } catch (\DomainException $e) {
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

        $action->execute($transferApplication, Auth::user(), $request->string('notes')->toString() ?: null);

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
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
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

        $action->execute($transferApplication, Auth::user(), $request->string('reason'));

        return back()->with('flash', ['message' => __('transfers.applicationRejected'), 'type' => 'success']);
    }

    public function withdraw(TransferApplication $transferApplication, WithdrawTransferApplicationAction $action, Request $request): RedirectResponse
    {
        $this->authorize('withdraw', $transferApplication);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $action->execute($transferApplication, Auth::user(), $data['reason']);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
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
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
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

        $action->execute($approval, Auth::user());

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

        $action->execute($approval, Auth::user(), $request->string('reason'));

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

        $action->execute($approval, Auth::user());

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

        $action->execute($approval, Auth::user(), $request->string('reason'));

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

        $action->execute($approval, Auth::user());

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

        $action->execute($approval, Auth::user(), $request->string('reason'));

        return back()->with('flash', ['message' => __('transfers.finalRejected'), 'type' => 'success']);
    }
}
