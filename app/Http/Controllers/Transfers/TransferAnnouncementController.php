<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfers;

use App\Actions\Transfers\CancelTransferAnnouncementAction;
use App\Actions\Transfers\CloseTransferAnnouncementAction;
use App\Actions\Transfers\PublishTransferAnnouncementAction;
use App\Actions\Transfers\UpdateTransferAnnouncementAction;
use App\Enums\AssignmentStatus;
use App\Enums\EstablishmentStatus;
use App\Enums\TransferAnnouncementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transfers\StoreTransferAnnouncementRequest;
use App\Http\Requests\Transfers\UpdateTransferAnnouncementRequest;
use App\Models\Organization;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\TransferAnnouncement;
use App\Models\TransferAnnouncementPosition;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TransferAnnouncementController extends Controller
{
    public function index(Request $request, OrganizationScopeService $scopeService): Response
    {
        $this->authorize('viewAny', TransferAnnouncement::class);

        /** @var User $user */
        $user = Auth::user();
        $accessibleOrgIds = $scopeService->accessibleOrganizationIds($user);

        $canUpdate = $user->can('transfers.announcements.update');
        $canPublish = $user->can('transfers.announcements.publish');
        $canClose = $user->can('transfers.announcements.close');

        $query = TransferAnnouncement::query()
            ->with(['organization', 'position', 'positions.organization', 'positions.position'])
            ->withCount('applications')
            ->when($accessibleOrgIds->isNotEmpty(), function ($q) use ($accessibleOrgIds): void {
                $q->whereHas('positions', fn ($p) => $p->whereIn('organization_id', $accessibleOrgIds));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $q->whereHas('organization', fn ($o) => $o->where('name_en', ci_like_operator(), $term)->orWhere('name_am', ci_like_operator(), $term))
                    ->orWhereHas('position', fn ($p) => $p->where('title_en', ci_like_operator(), $term)->orWhere('title_am', ci_like_operator(), $term));
            })
            ->latest();

        $paginator = $query->paginate(20)->withQueryString();

        $paginator->getCollection()->transform(function (TransferAnnouncement $a) use ($canUpdate, $canPublish, $canClose): array {
            return [
                'id' => $a->id,
                'organization' => $a->organization ? ['name_en' => $a->organization->name_en, 'name_am' => $a->organization->name_am] : null,
                'position' => $a->position ? ['title_en' => $a->position->title_en, 'title_am' => $a->position->title_am] : null,
                'grade_level' => $a->grade_level,
                'number_of_vacancies' => $a->totalVacancyCount(),
                'opening_date' => $a->opening_date?->format('Y-m-d'),
                'closing_date' => $a->closing_date?->format('Y-m-d'),
                'status' => $a->status->value,
                'applications_count' => $a->applications_count,
                'can' => [
                    'update' => $canUpdate && $a->status === TransferAnnouncementStatus::Draft,
                    'publish' => $canPublish && $a->status === TransferAnnouncementStatus::Draft,
                    'close' => $canClose && $a->status === TransferAnnouncementStatus::Published,
                    'cancel' => $canClose && ! $a->status->isFinal(),
                    'delete' => $canUpdate && $a->status === TransferAnnouncementStatus::Draft,
                ],
            ];
        });

        return Inertia::render('Transfers/Announcements/Index', [
            'announcements' => $paginator,
            'filters' => $request->only('status', 'search'),
            'can' => ['create' => $user->can('transfers.announcements.create')],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', TransferAnnouncement::class);

        return Inertia::render('Transfers/Announcements/Create', $this->formOptions());
    }

    public function store(StoreTransferAnnouncementRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $announcement = DB::transaction(function () use ($data): TransferAnnouncement {
            $positions = $data['positions'] ?? [];
            $firstPos = $positions[0] ?? null;

            $totalVacancies = array_sum(array_column($positions, 'vacancy_count'));

            $announcement = TransferAnnouncement::query()->create([
                'organization_id' => $firstPos['organization_id'] ?? null,
                'position_id' => $firstPos['position_id'] ?? null,
                'grade_level' => $firstPos['grade_level'] ?? null,
                'salary_min' => $firstPos['salary_min'] ?? null,
                'salary_max' => $firstPos['salary_max'] ?? null,
                'number_of_vacancies' => max(1, $totalVacancies),
                'eligibility_rules' => $data['eligibility_rules'] ?? null,
                'required_documents' => $data['required_documents'] ?? null,
                'opening_date' => $data['opening_date'],
                'closing_date' => $data['closing_date'],
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            foreach ($positions as $posData) {
                TransferAnnouncementPosition::query()->create([
                    'transfer_announcement_id' => $announcement->id,
                    'organization_id' => $posData['organization_id'],
                    'position_id' => $posData['position_id'],
                    'grade_level' => $posData['grade_level'] ?? null,
                    'salary_min' => $posData['salary_min'] ?? null,
                    'salary_max' => $posData['salary_max'] ?? null,
                    'vacancy_count' => (int) ($posData['vacancy_count'] ?? 1),
                ]);
            }

            return $announcement;
        });

        return to_route('transfer-announcements.show', $announcement)
            ->with('flash', ['message' => __('transfers.announcementCreated'), 'type' => 'success']);
    }

    public function show(TransferAnnouncement $transferAnnouncement): Response
    {
        $this->authorize('view', $transferAnnouncement);

        $transferAnnouncement->load([
            'organization',
            'position',
            'positions.organization',
            'positions.position',
            'publishedBy',
            'applications.employee',
            'applications.releasingOrganization',
        ]);

        /** @var User $user */
        $user = Auth::user();

        return Inertia::render('Transfers/Announcements/Show', [
            'announcement' => $transferAnnouncement,
            'can' => [
                'update' => $user->can('update', $transferAnnouncement),
                'publish' => $user->can('publish', $transferAnnouncement),
                'close' => $user->can('close', $transferAnnouncement),
                'cancel' => $user->can('cancel', $transferAnnouncement),
                'delete' => $user->can('delete', $transferAnnouncement),
            ],
        ]);
    }

    public function edit(TransferAnnouncement $transferAnnouncement): Response
    {
        $this->authorize('update', $transferAnnouncement);

        $transferAnnouncement->load(['positions.organization', 'positions.position']);

        // Always show already-selected positions in the dropdown even if now occupied
        $currentPositionIds = $transferAnnouncement->positions->pluck('position_id')->all();

        return Inertia::render('Transfers/Announcements/Edit', array_merge(
            ['announcement' => $transferAnnouncement],
            $this->formOptions($currentPositionIds),
        ));
    }

    public function update(
        UpdateTransferAnnouncementRequest $request,
        TransferAnnouncement $transferAnnouncement,
        UpdateTransferAnnouncementAction $action,
    ): RedirectResponse {
        $this->authorize('update', $transferAnnouncement);

        try {
            $action->execute($transferAnnouncement, $request->validated(), Auth::user());
        } catch (DomainException $e) {
            return back()->with('flash', ['message' => $e->getMessage(), 'type' => 'error']);
        }

        return to_route('transfer-announcements.show', $transferAnnouncement)
            ->with('flash', ['message' => __('transfers.announcementUpdated'), 'type' => 'success']);
    }

    public function publish(
        TransferAnnouncement $transferAnnouncement,
        PublishTransferAnnouncementAction $action,
    ): RedirectResponse {
        $this->authorize('publish', $transferAnnouncement);

        try {
            $action->execute($transferAnnouncement, Auth::user());
        } catch (DomainException $e) {
            return back()->with('flash', ['message' => $e->getMessage(), 'type' => 'error']);
        }

        return to_route('transfer-announcements.index')
            ->with('flash', ['message' => __('transfers.announcementPublished'), 'type' => 'success']);
    }

    public function close(
        TransferAnnouncement $transferAnnouncement,
        CloseTransferAnnouncementAction $action,
    ): RedirectResponse {
        $this->authorize('close', $transferAnnouncement);

        try {
            $action->execute($transferAnnouncement, Auth::user());
        } catch (DomainException $e) {
            return back()->with('flash', ['message' => $e->getMessage(), 'type' => 'error']);
        }

        return to_route('transfer-announcements.index')
            ->with('flash', ['message' => __('transfers.announcementClosed'), 'type' => 'success']);
    }

    public function cancel(
        TransferAnnouncement $transferAnnouncement,
        CancelTransferAnnouncementAction $action,
    ): RedirectResponse {
        $this->authorize('cancel', $transferAnnouncement);

        try {
            $action->execute($transferAnnouncement, Auth::user());
        } catch (DomainException $e) {
            return back()->with('flash', ['message' => $e->getMessage(), 'type' => 'error']);
        }

        return to_route('transfer-announcements.index')
            ->with('flash', ['message' => __('transfers.announcementCancelled'), 'type' => 'success']);
    }

    public function destroy(TransferAnnouncement $transferAnnouncement): RedirectResponse
    {
        $this->authorize('delete', $transferAnnouncement);

        $transferAnnouncement->delete();

        return to_route('transfer-announcements.index')
            ->with('flash', ['message' => __('transfers.announcementDeleted'), 'type' => 'success']);
    }

    /** @param string[] $includePositionIds Positions to always include regardless of occupancy (for edit). */
    private function formOptions(array $includePositionIds = []): array
    {
        $establishments = PositionEstablishment::query()
            ->where('status', EstablishmentStatus::Approved->value)
            ->withCount(['occupancies as active_count' => fn ($q) => $q->where('status', 'active')])
            ->get(['id', 'organization_id', 'position_id', 'approved_slots']);

        $vacancyLookup = $establishments->mapWithKeys(function ($e) {
            $available = max(0, $e->approved_slots - $e->active_count);

            return ["{$e->organization_id}_{$e->position_id}" => $available];
        });

        // Only include positions not currently occupied by an active employee assignment
        $positions = Position::query()
            ->where('is_active', true)
            ->whereDoesntHave('assignments', fn ($q) => $q
                ->where('assignment_status', AssignmentStatus::Active->value)
                ->where('is_current', true)
            )
            ->orderBy('title_en')
            ->get(['id', 'title_en', 'title_am', 'grade_level', 'organization_id']);

        // Only show positions that have at least one vacant slot, PLUS any already
        // selected on an existing announcement (so the edit form doesn't lose them).
        $positionsWithSlots = $positions
            ->map(fn ($pos) => [
                'id' => $pos->id,
                'title_en' => $pos->title_en,
                'title_am' => $pos->title_am,
                'grade_level' => $pos->grade_level,
                'organization_id' => $pos->organization_id,
                'available_slots' => $vacancyLookup->get("{$pos->organization_id}_{$pos->id}", 0),
            ])
            ->filter(fn ($p) => $p['available_slots'] > 0 || in_array($p['id'], $includePositionIds, true))
            ->values();

        return [
            'organizations' => Organization::query()
                ->where('status', 'active')
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_am']),
            'positions' => $positionsWithSlots,
        ];
    }
}
