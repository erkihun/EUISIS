<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Vacancy\CompleteVacancyTransferAction;
use App\Actions\Vacancy\ScreenVacancyApplicationAction;
use App\Actions\Vacancy\SelectVacancyApplicationAction;
use App\Actions\Vacancy\SubmitVacancyApplicationAction;
use App\Enums\VacancyApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\InitiateVacancyTransferRequest;
use App\Http\Requests\RejectVacancyApplicationRequest;
use App\Http\Requests\ScreenVacancyApplicationRequest;
use App\Http\Requests\SubmitVacancyApplicationRequest;
use App\Models\Employee;
use App\Models\VacancyAnnouncementPosition;
use App\Models\VacancyApplication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class VacancyApplicationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', VacancyApplication::class);

        $query = VacancyApplication::query()
            ->with(['announcement', 'positionEntry.position', 'positionEntry.organization', 'employee'])
            ->latest('applied_at');

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
