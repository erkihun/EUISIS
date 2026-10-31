<?php

declare(strict_types=1);

namespace Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Recruitment\Application\Actions\CompleteVacancyTransferAction;
use Modules\Recruitment\Application\Actions\ScreenVacancyApplicationAction;
use Modules\Recruitment\Application\Actions\SelectVacancyApplicationAction;
use Modules\Recruitment\Application\Actions\SubmitVacancyApplicationAction;
use Modules\Recruitment\Contracts\Data\VacancyApplicationStatus;
use Modules\Recruitment\Domain\Models\VacancyAnnouncementPosition;
use Modules\Recruitment\Domain\Models\VacancyApplication;
use Modules\Recruitment\Http\Requests\InitiateVacancyTransferRequest;
use Modules\Recruitment\Http\Requests\RejectVacancyApplicationRequest;
use Modules\Recruitment\Http\Requests\ScreenVacancyApplicationRequest;
use Modules\Recruitment\Http\Requests\SubmitVacancyApplicationRequest;

class VacancyApplicationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', VacancyApplication::class);

        $user = $request->user();
        $scope = app(OrganizationScopeService::class);

        $query = VacancyApplication::query()
            // Display fields only: never the applicant's full personnel record.
            ->with(['announcement', 'positionEntry.position', 'positionEntry.organization', 'employee:id,employee_number,full_name,name_en'])
            ->latest('applied_at');

        // A scoped officer sees applications from their organizations'
        // employees and applications to their organizations' vacancies.
        if (! $scope->isUnrestricted($user)) {
            $allowed = $scope->allowedOrganizationIds($user);
            $query->where(fn ($scoped) => $scoped
                ->whereIn('current_organization_id', $allowed)
                ->orWhereIn('vacancy_announcement_position_id', VacancyAnnouncementPosition::query()
                    ->select('id')
                    ->whereIn('organization_id', $allowed)));
        }

        if ($request->filled('vacancy_announcement_id')) {
            $query->where('vacancy_announcement_id', $request->string('vacancy_announcement_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return Inertia::render('VacancyApplications/Index', [
            'applications' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only('vacancy_announcement_id', 'status'),
        ]);
    }

    public function myApplications(Request $request): Response
    {
        $user = Auth::user();

        $applications = VacancyApplication::query()
            ->where('employee_id', $user->employee_id)
            ->with([
                'announcement',
                'positionEntry.organization',
                'positionEntry.position',
            ])
            ->latest('applied_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('VacancyApplications/MyApplications', [
            'applications' => $applications,
        ]);
    }

    public function show(VacancyApplication $vacancyApplication): Response
    {
        $this->authorize('view', $vacancyApplication);

        $user = Auth::user();
        $vacancyApplication->load([
            'announcement',
            'positionEntry.organization',
            'positionEntry.position',
            'employee',
            'documents',
        ]);

        return Inertia::render('VacancyApplications/Show', [
            'application' => $vacancyApplication,
            'can' => [
                'screen' => $user?->can('screen', $vacancyApplication) ?? false,
                'shortlist' => $user?->can('screen', $vacancyApplication) ?? false,
                'select' => $user?->can('select', $vacancyApplication) ?? false,
                'reject' => $user?->can('reject', $vacancyApplication) ?? false,
                'withdraw' => $user?->can('withdraw', $vacancyApplication) ?? false,
                'initiateTransfer' => $user?->can('initiateTransfer', $vacancyApplication) ?? false,
            ],
        ]);
    }

    public function store(
        SubmitVacancyApplicationRequest $request,
        SubmitVacancyApplicationAction $action,
    ): RedirectResponse {
        $positionEntry = VacancyAnnouncementPosition::findOrFail(
            $request->validated('vacancy_announcement_position_id'),
        );
        $announcement = $positionEntry->announcement;
        $employee = Employee::findOrFail($request->validated('employee_id'));

        $application = $action->execute($announcement, $positionEntry, $employee, $request->user());

        return to_route('vacancy-applications.show', $application)
            ->with('flash', ['message' => __('vacancies.applicationSubmitted'), 'type' => 'success']);
    }

    public function withdraw(VacancyApplication $vacancyApplication): RedirectResponse
    {
        $this->authorize('withdraw', $vacancyApplication);

        $vacancyApplication->update([
            'status' => VacancyApplicationStatus::Withdrawn->value,
            'withdrawn_at' => now(),
        ]);

        return to_route('vacancy-applications.my-applications')
            ->with('flash', ['message' => __('vacancies.applicationWithdrawn'), 'type' => 'success']);
    }

    public function screen(
        ScreenVacancyApplicationRequest $request,
        VacancyApplication $vacancyApplication,
        ScreenVacancyApplicationAction $action,
    ): RedirectResponse {
        $action->screen($vacancyApplication, $request->validated(), $request->user());

        return to_route('vacancy-applications.show', $vacancyApplication)
            ->with('flash', ['message' => __('vacancies.applicationScreened'), 'type' => 'success']);
    }

    public function shortlist(
        Request $request,
        VacancyApplication $vacancyApplication,
        ScreenVacancyApplicationAction $action,
    ): RedirectResponse {
        $this->authorize('screen', $vacancyApplication);

        $action->shortlist($vacancyApplication, $request->user());

        return to_route('vacancy-applications.show', $vacancyApplication)
            ->with('flash', ['message' => __('vacancies.applicationShortlisted'), 'type' => 'success']);
    }

    public function select(
        Request $request,
        VacancyApplication $vacancyApplication,
        SelectVacancyApplicationAction $action,
    ): RedirectResponse {
        $this->authorize('select', $vacancyApplication);

        $action->execute($vacancyApplication, $request->user());

        return to_route('vacancy-applications.show', $vacancyApplication)
            ->with('flash', ['message' => __('vacancies.applicationSelected'), 'type' => 'success']);
    }

    public function reject(
        RejectVacancyApplicationRequest $request,
        VacancyApplication $vacancyApplication,
        ScreenVacancyApplicationAction $action,
    ): RedirectResponse {
        $action->reject($vacancyApplication, $request->validated('rejection_reason'), $request->user());

        return to_route('vacancy-applications.show', $vacancyApplication)
            ->with('flash', ['message' => __('vacancies.applicationRejected'), 'type' => 'success']);
    }

    public function initiateTransfer(
        InitiateVacancyTransferRequest $request,
        VacancyApplication $vacancyApplication,
        CompleteVacancyTransferAction $action,
    ): RedirectResponse {
        $transfer = $action->execute($vacancyApplication, $request->validated(), $request->user());

        return to_route('transfers.dashboard')
            ->with('flash', ['message' => __('vacancies.transferCompleted'), 'type' => 'success']);
    }
}
