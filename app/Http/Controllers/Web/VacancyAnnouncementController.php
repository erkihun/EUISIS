<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Vacancy\PublishVacancyAnnouncementAction;
use App\Enums\EstablishmentStatus;
use App\Enums\VacancyAnnouncementStatus;
use App\Enums\VacancyApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVacancyAnnouncementRequest;
use App\Http\Requests\UpdateVacancyAnnouncementRequest;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\PositionEstablishment;
use App\Models\VacancyAnnouncement;
use App\Models\VacancyAnnouncementPosition;
use App\Models\VacancyApplication;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VacancyAnnouncementController extends Controller
{
    public function index(Request $request, OrganizationScopeService $organizationScopeService): Response
    {
        $this->authorize('viewAny', VacancyAnnouncement::class);

        $user = Auth::user();

        $query = VacancyAnnouncement::query()
            ->with(['positions.organization', 'positions.organizationUnit', 'positions.position'])
            ->withCount('applications')
            ->latest();

        if (
            $user !== null
            && ! $user->hasRole('Super Admin')
            && ! $user->hasRole('City Admin')
            && $user->organizationScopes()->exists()
        ) {
            $accessibleOrganizationIds = $organizationScopeService->accessibleOrganizationIds($user);

            $query->whereHas('positions', function ($positions) use ($accessibleOrganizationIds): void {
                $positions->whereIn('organization_id', $accessibleOrganizationIds);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return Inertia::render('Vacancies/Index', [
            'announcements' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only('status'),
            'can' => ['create' => $user?->can('create', VacancyAnnouncement::class) ?? false],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', VacancyAnnouncement::class);

        return Inertia::render('Vacancies/Create', [
            ...$this->formOptions(),
            'selectedEstablishmentId' => $request->string('establishment')->toString() ?: null,
        ]);
    }

    public function store(
        StoreVacancyAnnouncementRequest $request,
        PublishVacancyAnnouncementAction $action,
    ): RedirectResponse {
        $announcement = $action->createDraft($request->validated(), $request->user());

        return to_route('vacancy-announcements.show', $announcement)
            ->with('flash', ['message' => __('vacancies.created'), 'type' => 'success']);
    }

    public function show(VacancyAnnouncement $vacancyAnnouncement): Response
    {
        $this->authorize('view', $vacancyAnnouncement);

        $user = Auth::user();
        $vacancyAnnouncement->load([
            'positions.organization',
            'positions.organizationUnit',
            'positions.position',
            'positions.applications.employee',
            'publishedBy',
            'closedBy',
            'applications.employee',
        ]);

        $employeeId = $user?->employee_id;
        $appliedPositionEntryIds = [];

        if ($employeeId !== null) {
            $appliedPositionEntryIds = VacancyApplication::query()
                ->where('vacancy_announcement_id', $vacancyAnnouncement->id)
                ->where('employee_id', $employeeId)
                ->whereNotIn('status', [VacancyApplicationStatus::Withdrawn->value])
                ->pluck('vacancy_announcement_position_id')
                ->all();
        }

        return Inertia::render('Vacancies/Show', [
            'announcement' => $vacancyAnnouncement,
            'can' => [
                'update' => $user?->can('update', $vacancyAnnouncement) ?? false,
                'publish' => $user?->can('publish', $vacancyAnnouncement) ?? false,
                'close' => $user?->can('close', $vacancyAnnouncement) ?? false,
                'delete' => $user?->can('delete', $vacancyAnnouncement) ?? false,
                'apply' => $employeeId !== null && $vacancyAnnouncement->isAcceptingApplications(),
            ],
            'currentEmployeeId' => $employeeId,
            'appliedPositionEntryIds' => $appliedPositionEntryIds,
        ]);
    }

    public function edit(VacancyAnnouncement $vacancyAnnouncement): Response
    {
        $this->authorize('update', $vacancyAnnouncement);

        $vacancyAnnouncement->load(['positions']);

        return Inertia::render('Vacancies/Edit', [
            'announcement' => $vacancyAnnouncement,
            ...$this->formOptions(),
        ]);
    }

    public function update(
        UpdateVacancyAnnouncementRequest $request,
        VacancyAnnouncement $vacancyAnnouncement,
    ): RedirectResponse {
        if ($vacancyAnnouncement->status !== VacancyAnnouncementStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => __('vacancies.notDraft'),
            ]);
        }

        $data = $request->validated();

        DB::transaction(function () use ($data, $vacancyAnnouncement): void {
            $vacancyAnnouncement->update([
                'title_en' => $data['title_en'],
                'title_am' => $data['title_am'] ?? null,
                'description_en' => $data['description_en'] ?? null,
                'description_am' => $data['description_am'] ?? null,
                'application_opens_at' => $data['application_opens_at'] ?? null,
                'application_closes_at' => $data['application_closes_at'] ?? null,
                'eligibility_rules' => $data['eligibility_rules'] ?? null,
            ]);

            $vacancyAnnouncement->positions()->delete();

            foreach ($data['positions'] as $position) {
                $establishment = PositionEstablishment::query()->findOrFail($position['position_establishment_id']);

                if ($establishment->status !== EstablishmentStatus::Approved) {
                    throw ValidationException::withMessages([
                        'positions' => __('vacancies.establishmentNotApproved'),
                    ]);
                }

                VacancyAnnouncementPosition::query()->create([
                    'vacancy_announcement_id' => $vacancyAnnouncement->id,
                    'position_establishment_id' => $establishment->id,
                    'organization_id' => $establishment->organization_id,
                    'organization_unit_id' => $establishment->organization_unit_id,
                    'position_id' => $establishment->position_id,
                    'vacancy_slots' => $position['vacancy_slots'],
                ]);
            }
        });

        return to_route('vacancy-announcements.show', $vacancyAnnouncement)
            ->with('flash', ['message' => __('vacancies.updated'), 'type' => 'success']);
    }

    public function publish(
        VacancyAnnouncement $vacancyAnnouncement,
        PublishVacancyAnnouncementAction $action,
    ): RedirectResponse {
        $this->authorize('publish', $vacancyAnnouncement);

        $action->publish($vacancyAnnouncement, Auth::user());

        return to_route('vacancy-announcements.show', $vacancyAnnouncement)
            ->with('flash', ['message' => __('vacancies.published'), 'type' => 'success']);
    }

    public function close(VacancyAnnouncement $vacancyAnnouncement): RedirectResponse
    {
        $this->authorize('close', $vacancyAnnouncement);

        $user = Auth::user();
        $vacancyAnnouncement->update([
            'status' => VacancyAnnouncementStatus::Closed->value,
            'closed_by' => $user->id,
            'closed_at' => now(),
        ]);

        return to_route('vacancy-announcements.show', $vacancyAnnouncement)
            ->with('flash', ['message' => __('vacancies.closed'), 'type' => 'success']);
    }

    public function destroy(VacancyAnnouncement $vacancyAnnouncement): RedirectResponse
    {
        $this->authorize('delete', $vacancyAnnouncement);

        $vacancyAnnouncement->delete();

        return to_route('vacancy-announcements.index')
            ->with('flash', ['message' => __('vacancies.deleted'), 'type' => 'success']);
    }

    private function formOptions(): array
    {
        return [
            'organizations' => Organization::query()
                ->where('status', 'active')
                ->orderBy('name_en')
                ->get(['id', 'name_en']),
            'units' => OrganizationUnit::query()
                ->where('status', 'active')
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'organization_id']),
            'establishments' => PositionEstablishment::query()
                ->where('status', EstablishmentStatus::Approved->value)
                ->with(['position:id,title_en', 'organization:id,name_en', 'organizationUnit:id,name_en'])
                ->get(['id', 'organization_id', 'organization_unit_id', 'position_id', 'approved_slots']),
        ];
    }
}
