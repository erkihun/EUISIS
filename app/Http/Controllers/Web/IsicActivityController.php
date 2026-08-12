<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\IsicActivities\ArchiveIsicActivityAction;
use App\Actions\IsicActivities\CreateIsicActivityAction;
use App\Actions\IsicActivities\RestoreIsicActivityAction;
use App\Actions\IsicActivities\UpdateIsicActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIsicActivityRequest;
use App\Http\Requests\UpdateIsicActivityRequest;
use App\Http\Resources\IsicActivityResource;
use App\Models\IsicActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IsicActivityController extends Controller
{
    private const LEVELS = ['section', 'division', 'group', 'class'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', IsicActivity::class);

        $query = IsicActivity::query()
            ->when($request->string('search')->toString() !== '', function ($q) use ($request): void {
                $search = $request->string('search')->toString();
                $q->where(function ($nested) use ($search): void {
                    $nested->where('isic_code', ci_like_operator(), "%{$search}%")
                        ->orWhere('name_en', ci_like_operator(), "%{$search}%")
                        ->orWhere('name_am', ci_like_operator(), "%{$search}%");
                });
            })
            ->when($request->filled('level'), fn ($q) => $q->where('level', $request->string('level')->toString()))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('sort_order')
            ->orderBy('isic_code');

        $activities = $query->paginate(30)->withQueryString();

        return Inertia::render('IsicActivities/Index', [
            'isicActivities' => IsicActivityResource::collection($activities)->resolve(),
            'meta' => [
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'total' => $activities->total(),
                'per_page' => $activities->perPage(),
            ],
            'filters' => $request->only(['search', 'level', 'is_active']),
            'levels' => self::LEVELS,
            'can' => [
                'create' => $request->user()?->can('create', IsicActivity::class) ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', IsicActivity::class);

        return Inertia::render('IsicActivities/Create', [
            'levels' => self::LEVELS,
            'parents' => $this->parentOptions(),
        ]);
    }

    public function store(StoreIsicActivityRequest $request, CreateIsicActivityAction $action): RedirectResponse
    {
        $activity = $action->execute($request->validated(), $request->user());

        return to_route('isic-activities.show', $activity)
            ->with('flash', ['message' => __('isic-activities.created_successfully'), 'type' => 'success']);
    }

    public function show(IsicActivity $isicActivity): Response
    {
        $this->authorize('view', $isicActivity);

        return Inertia::render('IsicActivities/Show', [
            'isicActivity' => (new IsicActivityResource($isicActivity))->resolve(),
        ]);
    }

    public function edit(IsicActivity $isicActivity): Response
    {
        $this->authorize('update', $isicActivity);

        return Inertia::render('IsicActivities/Edit', [
            'isicActivity' => (new IsicActivityResource($isicActivity))->resolve(),
            'levels' => self::LEVELS,
            'parents' => $this->parentOptions($isicActivity->id),
        ]);
    }

    public function update(UpdateIsicActivityRequest $request, IsicActivity $isicActivity, UpdateIsicActivityAction $action): RedirectResponse
    {
        $action->execute($isicActivity, $request->validated(), $request->user());

        return to_route('isic-activities.show', $isicActivity)
            ->with('flash', ['message' => __('isic-activities.updated_successfully'), 'type' => 'success']);
    }

    public function archive(Request $request, IsicActivity $isicActivity, ArchiveIsicActivityAction $action): RedirectResponse
    {
        $this->authorize('archive', $isicActivity);

        $action->execute($isicActivity, $request->user(), $request->string('reason')->toString() ?: null, $request);

        return to_route('isic-activities.index')
            ->with('flash', ['message' => __('isic-activities.archived_successfully'), 'type' => 'success']);
    }

    public function restore(Request $request, string $isicActivity, RestoreIsicActivityAction $action): RedirectResponse
    {
        $activity = IsicActivity::query()->withTrashed()->findOrFail($isicActivity);

        $this->authorize('restore', $activity);

        $action->execute($activity, $request->user(), $request);

        return back()->with('flash', ['message' => __('isic-activities.restored_successfully'), 'type' => 'success']);
    }

    /**
     * @return array<int, array{id: string, isic_code: string, name_en: string|null, level: string}>
     */
    private function parentOptions(?string $excludeId = null): array
    {
        return IsicActivity::query()
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where('is_active', true)
            ->orderBy('isic_code')
            ->get(['id', 'isic_code', 'name_en', 'level'])
            ->map(fn (IsicActivity $a) => [
                'id' => $a->id,
                'isic_code' => $a->isic_code,
                'name_en' => $a->name_en,
                'level' => $a->level,
            ])
            ->all();
    }
}
