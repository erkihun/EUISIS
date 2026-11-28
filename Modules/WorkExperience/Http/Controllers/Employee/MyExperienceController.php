<?php

declare(strict_types=1);

namespace Modules\WorkExperience\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\WorkExperience\Application\Services\WorkExperienceAccess;
use Modules\WorkExperience\Application\Services\WorkExperiencePresenter;
use Modules\WorkExperience\Application\Services\WorkExperienceRequests;
use Modules\WorkExperience\Contracts\Data\WorkExperienceStatus;
use Modules\WorkExperience\Domain\Models\WorkExperienceRequest;

/**
 * My Portal > My work experience. The employee reads what the office recorded
 * about their service and asks for a written certificate with a short form.
 * No route here takes an employee id: it is always the user's own record.
 */
class MyExperienceController extends Controller
{
    public function __construct(
        private readonly WorkExperienceAccess $access,
        private readonly WorkExperiencePresenter $presenter,
        private readonly WorkExperienceRequests $requests,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('work_experience.request_own'), 403);
        $employee = $this->access->employeeOf($user);
        abort_if($employee === null, 403);

        $mine = WorkExperienceRequest::query()->with(['employee', 'organization:id,name_en,name_am'])
            ->where('employee_id', $employee->id)->orderByDesc('created_at')->limit(50)->get();

        return Inertia::render('modules/work-experience/MyExperience', [
            'experienceEmployee' => $this->presenter->employee($employee),
            'experience' => $this->presenter->experience($employee),
            'experienceRequests' => $mine->map(fn (WorkExperienceRequest $row): array => [
                ...$this->presenter->row($row),
                'can_cancel' => $row->status === WorkExperienceStatus::Submitted,
            ])->all(),
            'experienceOpen' => is_string($request->query('request')) ? $request->query('request') : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('work_experience.request_own'), 403);
        $employee = $this->access->employeeOf($user);
        abort_if($employee === null, 403);

        $data = $request->validate([
            'addressee' => ['nullable', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:500'],
            'language' => ['required', 'in:am,en'],
        ], [], [
            'addressee' => __('work_experience::messages.fields.addressee'),
            'purpose' => __('work_experience::messages.fields.purpose'),
            'language' => __('work_experience::messages.fields.language'),
        ]);

        $this->requests->submit($user, $employee, $data);

        return back()->with('success', __('work_experience::messages.flash.submitted'));
    }

    public function cancel(Request $request, WorkExperienceRequest $experienceRequest): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('work_experience.request_own') && $this->access->isOwner($user, $experienceRequest), 403);

        $this->requests->cancel($user, $experienceRequest);

        return back()->with('success', __('work_experience::messages.flash.cancelled'));
    }
}
