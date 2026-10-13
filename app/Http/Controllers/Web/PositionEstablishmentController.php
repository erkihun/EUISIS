<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Vacancy\ApprovePositionEstablishmentAction;
use App\Actions\Vacancy\StorePositionEstablishmentAction;
use App\Enums\EstablishmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePositionEstablishmentRequest;
use App\Http\Requests\UpdatePositionEstablishmentRequest;
use App\Models\PositionEstablishment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PositionEstablishmentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PositionEstablishment::class);

        // The same filter bar as every other list: search by number, status and organization, kept in the query string.
        $statuses = array_column(EstablishmentStatus::cases(), 'value');
        $status = $request->string('status')->toString();
        $filters = [
            'search' => $request->string('search')->trim()->toString(),
            'status' => in_array($status, $statuses, true) ? $status : '',
            'organization_id' => $request->string('organization_id')->toString(),
        ];

        $query = PositionEstablishment::query()
            ->with(['organization', 'position', 'occupation', 'approvedBy'])
            ->when($filters['search'] !== '', fn ($q) => $q->where('establishment_number', ci_like_operator(), '%'.$filters['search'].'%'))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['organization_id'] !== '', fn ($q) => $q->where('organization_id', $filters['organization_id']))
            ->latest();

        return Inertia::render('PositionEstablishments/Index', [
            'establishments' => $query->paginate(20)->withQueryString(),
            'filters' => $filters,
            'filterOptions' => ['statuses' => $statuses],
        ]);
    }

    public function store(
        StorePositionEstablishmentRequest $request,
        StorePositionEstablishmentAction $action,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $action->execute($request->validated(), $user);

        return back()->with('flash', ['message' => __('position-establishments.created'), 'type' => 'success']);
    }

    public function show(PositionEstablishment $positionEstablishment): Response
    {
        $this->authorize('view', $positionEstablishment);

        $positionEstablishment->load(['organization', 'organizationUnit', 'position', 'occupation', 'approvedBy', 'occupancies.employee', 'vacancyAnnouncements']);

        /** @var User $user */
        $user = Auth::user();

        return Inertia::render('PositionEstablishments/Show', [
            'establishment' => $positionEstablishment,
            'can' => [
                'update' => $user->can('update', $positionEstablishment),
                'approve' => $user->can('approve', $positionEstablishment),
                'archive' => $user->can('archive', $positionEstablishment),
            ],
        ]);
    }

    public function edit(PositionEstablishment $positionEstablishment): Response
    {
        $this->authorize('update', $positionEstablishment);

        return Inertia::render('PositionEstablishments/Edit', [
            'establishment' => $positionEstablishment,
        ]);
    }

    public function update(
        UpdatePositionEstablishmentRequest $request,
        PositionEstablishment $positionEstablishment,
    ): RedirectResponse {
        $positionEstablishment->update($request->validated());

        return to_route('position-establishments.show', $positionEstablishment)
            ->with('flash', ['message' => __('position-establishments.updated'), 'type' => 'success']);
    }

    public function approve(
        PositionEstablishment $positionEstablishment,
        ApprovePositionEstablishmentAction $action,
    ): RedirectResponse {
        $this->authorize('approve', $positionEstablishment);

        /** @var User $user */
        $user = Auth::user();

        $action->execute($positionEstablishment, $user);

        return back()->with('flash', ['message' => __('position-establishments.approved'), 'type' => 'success']);
    }

    public function archive(PositionEstablishment $positionEstablishment): RedirectResponse
    {
        $this->authorize('archive', $positionEstablishment);

        $positionEstablishment->update(['status' => EstablishmentStatus::Archived->value]);
        $positionEstablishment->delete();

        return to_route('position-establishments.index')
            ->with('flash', ['message' => __('position-establishments.archived'), 'type' => 'success']);
    }
}
