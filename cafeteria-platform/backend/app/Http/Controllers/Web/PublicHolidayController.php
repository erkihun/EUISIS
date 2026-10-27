<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Cafeteria\ArchivePublicHolidayAction;
use App\Actions\Cafeteria\CreatePublicHolidayAction;
use App\Actions\Cafeteria\UpdatePublicHolidayAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicHolidayRequest;
use App\Http\Requests\UpdatePublicHolidayRequest;
use App\Http\Resources\PublicHolidayResource;
use App\Models\PublicHoliday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicHolidayController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PublicHoliday::class);

        $yearFilter = $request->integer('year') ?: now()->year;

        $query = PublicHoliday::query()
            ->when($request->boolean('include_inactive', false), fn ($q) => $q->withTrashed())
            ->whereYear('holiday_date', $yearFilter)
            ->when($request->string('search')->toString() !== '', function ($q) use ($request): void {
                $search = $request->string('search')->toString();
                $q->where(function ($nested) use ($search): void {
                    $nested->where('name_en', ci_like_operator(), "%{$search}%")
                        ->orWhere('name_am', ci_like_operator(), "%{$search}%");
                });
            })
            ->orderBy('holiday_date');

        $holidays = $query->paginate(50)->withQueryString();

        return Inertia::render('Cafeteria/Holidays/Index', [
            'holidays' => PublicHolidayResource::collection($holidays)->resolve(),
            'meta' => [
                'current_page' => $holidays->currentPage(),
                'last_page' => $holidays->lastPage(),
                'total' => $holidays->total(),
                'per_page' => $holidays->perPage(),
            ],
            'filters' => $request->only(['search', 'year', 'include_inactive']),
            'year' => $yearFilter,
            'can' => [
                'create' => $request->user()?->can('create', PublicHoliday::class) ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', PublicHoliday::class);

        return Inertia::render('Cafeteria/Holidays/Create');
    }

    public function store(StorePublicHolidayRequest $request, CreatePublicHolidayAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $request->user(), $request);

        return to_route('cafeteria.holidays.index')
            ->with('flash', ['message' => __('cafeteria.holidayCreated'), 'type' => 'success']);
    }

    public function edit(PublicHoliday $publicHoliday): Response
    {
        $this->authorize('update', $publicHoliday);

        return Inertia::render('Cafeteria/Holidays/Edit', [
            'holiday' => (new PublicHolidayResource($publicHoliday))->resolve(),
        ]);
    }

    public function update(UpdatePublicHolidayRequest $request, PublicHoliday $publicHoliday, UpdatePublicHolidayAction $action): RedirectResponse
    {
        $action->execute($publicHoliday, $request->validated(), $request->user(), $request);

        return to_route('cafeteria.holidays.index')
            ->with('flash', ['message' => __('cafeteria.holidayUpdated'), 'type' => 'success']);
    }

    public function archive(Request $request, PublicHoliday $publicHoliday, ArchivePublicHolidayAction $action): RedirectResponse
    {
        $this->authorize('archive', $publicHoliday);

        $action->execute($publicHoliday, $request->user(), $request->string('reason')->toString() ?: null, $request);

        return to_route('cafeteria.holidays.index')
            ->with('flash', ['message' => __('cafeteria.holidayArchived'), 'type' => 'success']);
    }
}
