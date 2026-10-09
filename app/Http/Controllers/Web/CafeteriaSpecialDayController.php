<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Cafeteria\ArchiveCafeteriaSpecialDayAction;
use App\Actions\Cafeteria\CreateCafeteriaSpecialDayAction;
use App\Actions\Cafeteria\RestoreCafeteriaSpecialDayAction;
use App\Actions\Cafeteria\UpdateCafeteriaSpecialDayAction;
use App\Enums\CafeteriaSpecialDayType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCafeteriaSpecialDayRequest;
use App\Http\Requests\UpdateCafeteriaSpecialDayRequest;
use App\Http\Resources\CafeteriaSpecialDayResource;
use App\Models\CafeteriaSpecialDay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CafeteriaSpecialDayController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CafeteriaSpecialDay::class);

        $isArchivedFilter = $request->boolean('archived', false);

        $query = CafeteriaSpecialDay::query()
            ->when($isArchivedFilter, fn ($q) => $q->onlyTrashed(), fn ($q) => $q->withoutTrashed())
            ->when($request->string('search')->toString() !== '', function ($q) use ($request): void {
                $s = $request->string('search')->toString();
                $q->where(fn ($n) => $n->where('name_en', ci_like_operator(), "%{$s}%")->orWhere('name_am', ci_like_operator(), "%{$s}%"));
            })
            ->when($request->string('year')->toString() !== '', fn ($q) => $q->whereYear('special_date', $request->integer('year')))
            ->orderBy('special_date', 'desc');

        $days = $query->paginate(30)->withQueryString();

        return Inertia::render('Cafeteria/SpecialDays/Index', [
            'days' => CafeteriaSpecialDayResource::collection($days)->resolve(),
            'meta' => ['current_page' => $days->currentPage(), 'last_page' => $days->lastPage(), 'total' => $days->total()],
            'filters' => $request->only(['search', 'archived', 'year']),
            'day_types' => collect(CafeteriaSpecialDayType::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->values(),
            'can' => ['create' => $request->user()?->can('create', CafeteriaSpecialDay::class) ?? false],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', CafeteriaSpecialDay::class);

        return Inertia::render('Cafeteria/SpecialDays/Create', [
            'day_types' => collect(CafeteriaSpecialDayType::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->values(),
        ]);
    }

    public function store(StoreCafeteriaSpecialDayRequest $request, CreateCafeteriaSpecialDayAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $request->user(), $request);

        return to_route('cafeteria.special-days.index')
            ->with('flash', ['message' => __('cafeteria.specialDayCreated'), 'type' => 'success']);
    }

    public function edit(CafeteriaSpecialDay $cafeteriaSpecialDay): Response
    {
        $this->authorize('update', $cafeteriaSpecialDay);

        return Inertia::render('Cafeteria/SpecialDays/Edit', [
            'day' => (new CafeteriaSpecialDayResource($cafeteriaSpecialDay))->resolve(),
            'day_types' => collect(CafeteriaSpecialDayType::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->values(),
        ]);
    }

    public function update(UpdateCafeteriaSpecialDayRequest $request, CafeteriaSpecialDay $cafeteriaSpecialDay, UpdateCafeteriaSpecialDayAction $action): RedirectResponse
    {
        $action->execute($cafeteriaSpecialDay, $request->validated(), $request->user(), $request);

        return to_route('cafeteria.special-days.index')
            ->with('flash', ['message' => __('cafeteria.specialDayUpdated'), 'type' => 'success']);
    }

    public function archive(Request $request, CafeteriaSpecialDay $cafeteriaSpecialDay, ArchiveCafeteriaSpecialDayAction $action): RedirectResponse
    {
        $this->authorize('archive', $cafeteriaSpecialDay);

        $action->execute($cafeteriaSpecialDay, $request->user(), $request->string('reason')->toString() ?: null, $request);

        return to_route('cafeteria.special-days.index')
            ->with('flash', ['message' => __('cafeteria.specialDayArchived'), 'type' => 'success']);
    }

    public function restore(Request $request, CafeteriaSpecialDay $cafeteriaSpecialDay, RestoreCafeteriaSpecialDayAction $action): RedirectResponse
    {
        $this->authorize('restore', $cafeteriaSpecialDay);

        $action->execute($cafeteriaSpecialDay, $request->user(), $request);

        return to_route('cafeteria.special-days.index')
            ->with('flash', ['message' => __('cafeteria.specialDayRestored'), 'type' => 'success']);
    }
}
