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

        $query = PositionEstablishment::query()
            ->with(['organization', 'position', 'occupation', 'approvedBy'])
            ->latest();

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->string('organization_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return Inertia::render('PositionEstablishments/Index', [
            'establishments' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only('organization_id', 'status'),
        ]);
    }

    public function store(
        StorePositionEstablishmentRequest $request,
        StorePositionEstablishmentAction $action,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $action->execute($request->validated(), $user);

        return back()->with('flash', ['message' => __('positionEstablishments.created'), 'type' => 'success']);
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
            ->with('flash', ['message' => __('positionEstablishments.updated'), 'type' => 'success']);
    }

    public function approve(
        PositionEstablishment $positionEstablishment,
        ApprovePositionEstablishmentAction $action,
    ): RedirectResponse {
        $this->authorize('approve', $positionEstablishment);

        /** @var User $user */
        $user = Auth::user();

        $action->execute($positionEstablishment, $user);

        return back()->with('flash', ['message' => __('positionEstablishments.approved'), 'type' => 'success']);
    }

    public function archive(PositionEstablishment $positionEstablishment): RedirectResponse
    {
        $this->authorize('archive', $positionEstablishment);

        $positionEstablishment->update(['status' => EstablishmentStatus::Archived->value]);
        $positionEstablishment->delete();

        return to_route('position-establishments.index')
            ->with('flash', ['message' => __('positionEstablishments.archived'), 'type' => 'success']);
    }
}
