<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Http\Requests\Admin\StorePerformanceCategoryRequest;
use Modules\Court\Http\Requests\Admin\StorePerformanceCriterionRequest;
use Modules\Court\Http\Requests\Admin\UpdatePerformanceCriterionRequest;
use Modules\Court\Domain\Models\PerformanceEvaluationCategory;
use Modules\Court\Domain\Models\PerformanceCriterion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PerformanceEvaluationSettingController extends Controller
{
    public function index(Request $request): Response
    {
        $criteria = PerformanceCriterion::query()
            ->withCount('scores')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $categories = $this->categories();

        $totalActiveWeight = (int) PerformanceCriterion::query()
            ->where('active', true)
            ->sum('weight');

        // The criteria and their weights (React, Court/PerformanceSettings/Index).
        return Inertia::render('Court/PerformanceSettings/Index', [
            'criteria' => $criteria->map(fn (PerformanceCriterion $criterion): array => [
                'id' => (int) $criterion->id,
                'name' => (string) $criterion->name,
                'name_am' => $criterion->name_am,
                'category' => (string) $criterion->category,
                'category_label' => (string) ($categories->firstWhere('slug', $criterion->category)?->local_name ?? $criterion->category),
                'weight' => (int) $criterion->weight,
                'sort_order' => (int) $criterion->sort_order,
                'active' => (bool) $criterion->active,
                'description' => $criterion->description,
                'scores_count' => (int) ($criterion->scores_count ?? $criterion->scores()->count()),
            ])->values()->all(),
            'categories' => $this->categoryOptions($categories),
            'totalActiveWeight' => $totalActiveWeight,
            // The Add Criterion / Add Category dialogs come back here (redirect_to).
            'returnUrl' => $request->fullUrl(),
        ]);
    }

    public function createCriterion(): Response
    {
        // The Add Criterion page (React, Court/PerformanceSettings/CreateCriterion).
        return Inertia::render('Court/PerformanceSettings/CreateCriterion', [
            'categories' => $this->categoryOptions($this->categories()),
        ]);
    }

    public function createCategory(): Response
    {
        // The Add Category page (React, Court/PerformanceSettings/CreateCategory).
        return Inertia::render('Court/PerformanceSettings/CreateCategory');
    }

    /**
     * The category picker: each category's slug and its name in the current language.
     *
     * @return list<array{slug: string, name: string}>
     */
    private function categoryOptions(Collection $categories): array
    {
        return $categories->map(fn (object $category): array => [
            'slug' => (string) $category->slug,
            'name' => (string) $category->local_name,
        ])->values()->all();
    }

    public function store(StorePerformanceCriterionRequest $request): RedirectResponse
    {
        PerformanceCriterion::query()->create($this->payload($request->validated(), $request->boolean('active', true)));

        return redirect()
            ->to($this->redirectTarget($request->input('redirect_to')))
            ->with('success', __('court::performance.settings.messages.created'));
    }

    public function storeCategory(StorePerformanceCategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $slug = Str::slug((string) ($data['slug'] ?? $data['name']), '_');
        if ($slug === '') {
            $slug = Str::random(8);
        }

        if (PerformanceEvaluationCategory::query()->where('slug', $slug)->exists()) {
            return back()
                ->withInput()
                ->withErrors(['slug' => __('court::performance.settings.messages.category_slug_exists')]);
        }

        PerformanceEvaluationCategory::query()->create([
            'slug' => $slug,
            'name' => $data['name'],
            'name_am' => $data['name_am'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'active' => $request->boolean('active', true),
        ]);

        return redirect()
            ->to($this->redirectTarget($request->input('redirect_to')))
            ->with('success', __('court::performance.settings.messages.category_created'));
    }

    public function update(UpdatePerformanceCriterionRequest $request, PerformanceCriterion $criterion): RedirectResponse
    {
        $criterion->update($this->payload($request->validated(), $request->boolean('active')));

        return redirect()
            ->route('court.settings.performance-evaluation.index')
            ->with('success', __('court::performance.settings.messages.updated'));
    }

    public function destroy(PerformanceCriterion $criterion): RedirectResponse
    {
        if ($criterion->scores()->exists()) {
            $criterion->update(['active' => false]);

            return redirect()
                ->route('court.settings.performance-evaluation.index')
                ->with('success', __('court::performance.settings.messages.deactivated_in_use'));
        }

        $criterion->delete();

        return redirect()
            ->route('court.settings.performance-evaluation.index')
            ->with('success', __('court::performance.settings.messages.deleted'));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(array $data, bool $active): array
    {
        return [
            'name' => $data['name'],
            'name_am' => $data['name_am'] ?? null,
            'category' => $data['category'],
            'weight' => (int) $data['weight'],
            'description' => $data['description'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'active' => $active,
        ];
    }

    private function categories()
    {
        $configured = PerformanceEvaluationCategory::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($configured->isNotEmpty()) {
            return $configured;
        }

        return PerformanceCriterion::query()
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->filter()
            ->map(fn (string $category): object => (object) [
                'slug' => $category,
                'local_name' => $category,
            ])
            ->values();
    }

    /** Only follow `redirect_to` back into this application: a same-origin URL or a root-relative path. */
    private function redirectTarget(mixed $target): string
    {
        if (! is_string($target) || $target === '') {
            return route('court.settings.performance-evaluation.index');
        }

        // "/path" is local; "//host" and "/\host" are protocol-relative to another host.
        if (str_starts_with($target, '/') && ! str_starts_with($target, '//') && ! str_starts_with($target, '/\\')) {
            return $target;
        }

        $app = parse_url(url('/'));
        $parts = parse_url($target);

        if (
            is_array($parts) && is_array($app)
            && ($parts['scheme'] ?? null) === ($app['scheme'] ?? null)
            && ($parts['host'] ?? null) === ($app['host'] ?? null)
            && ($parts['port'] ?? null) === ($app['port'] ?? null)
            && ! isset($parts['user'])
        ) {
            return $target;
        }

        return route('court.settings.performance-evaluation.index');
    }
}
