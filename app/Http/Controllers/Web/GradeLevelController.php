<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\GradeLevels\ArchiveGradeLevelAction;
use App\Actions\GradeLevels\CreateGradeLevelAction;
use App\Actions\GradeLevels\RestoreGradeLevelAction;
use App\Actions\GradeLevels\UpdateGradeLevelAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGradeLevelRequest;
use App\Http\Requests\UpdateGradeLevelRequest;
use App\Http\Resources\GradeLevelResource;
use App\Models\GradeLevel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GradeLevelController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', GradeLevel::class);

        $query = GradeLevel::query()
            ->when($request->string('search')->toString() !== '', function ($q) use ($request): void {
                $search = $request->string('search')->toString();
                $q->where('name', ci_like_operator(), "%{$search}%");
            })
            ->when(
                $request->filled('is_active') && $request->string('is_active')->toString() === '0',
                fn ($q) => $q->onlyTrashed(),
            )
            ->when(
                ! $request->filled('is_active') || $request->string('is_active')->toString() === '1',
                fn ($q) => $q->withoutTrashed(),
            )
            ->orderBy('name');

        $gradeLevels = $query->paginate(30)->withQueryString();

        return Inertia::render('GradeLevels/Index', [
            'gradeLevels' => GradeLevelResource::collection($gradeLevels)->resolve(),
            'meta' => [
                'current_page' => $gradeLevels->currentPage(),
                'last_page' => $gradeLevels->lastPage(),
                'total' => $gradeLevels->total(),
                'per_page' => $gradeLevels->perPage(),
            ],
            'filters' => $request->only(['search', 'is_active']),
            'can' => [
                'create' => $request->user()?->can('create', GradeLevel::class) ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', GradeLevel::class);

        return Inertia::render('GradeLevels/Create');
    }

    public function store(StoreGradeLevelRequest $request, CreateGradeLevelAction $action): RedirectResponse
    {
        $gradeLevel = $action->execute($request->validated(), $request->user());

        return to_route('grade-levels.show', $gradeLevel)
            ->with('flash', ['message' => __('grade-levels.created_successfully'), 'type' => 'success']);
    }

    public function show(GradeLevel $gradeLevel): Response
    {
        $this->authorize('view', $gradeLevel);

        return Inertia::render('GradeLevels/Show', [
            'gradeLevel' => (new GradeLevelResource($gradeLevel))->resolve(),
        ]);
    }

    public function edit(GradeLevel $gradeLevel): Response
    {
        $this->authorize('update', $gradeLevel);

        return Inertia::render('GradeLevels/Edit', [
            'gradeLevel' => (new GradeLevelResource($gradeLevel))->resolve(),
        ]);
    }

    public function update(UpdateGradeLevelRequest $request, GradeLevel $gradeLevel, UpdateGradeLevelAction $action): RedirectResponse
    {
        $action->execute($gradeLevel, $request->validated(), $request->user());

        return to_route('grade-levels.show', $gradeLevel)
            ->with('flash', ['message' => __('grade-levels.updated_successfully'), 'type' => 'success']);
    }

    public function archive(Request $request, GradeLevel $gradeLevel, ArchiveGradeLevelAction $action): RedirectResponse
    {
        $this->authorize('archive', $gradeLevel);

        $action->execute($gradeLevel, $request->user(), $request->string('reason')->toString() ?: null, $request);

        return to_route('grade-levels.index')
            ->with('flash', ['message' => __('grade-levels.archived_successfully'), 'type' => 'success']);
    }

    public function restore(Request $request, string $gradeLevel, RestoreGradeLevelAction $action): RedirectResponse
    {
        $model = GradeLevel::query()->withTrashed()->findOrFail($gradeLevel);

        $this->authorize('restore', $model);

        $action->execute($model, $request->user(), $request);

        return back()->with('flash', ['message' => __('grade-levels.restored_successfully'), 'type' => 'success']);
    }
}
