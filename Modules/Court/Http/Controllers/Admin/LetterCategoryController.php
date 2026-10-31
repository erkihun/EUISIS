<?php

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\LetterCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LetterCategoryController extends Controller
{
    /** The register (React, Court/LetterCategories/Index); the actions follow the Blade page's permissions. */
    public function index(Request $request): Response
    {
        $categories = LetterCategory::query()
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        $user = $request->user();
        $can = fn (string $permission): bool => (bool) ($user?->hasPermission($permission) ?? false);

        return Inertia::render('Court/LetterCategories/Index', [
            'categories' => $categories->through(fn (LetterCategory $category): array => $this->row($category)),
            'can' => [
                'create' => $can('letters.templet.create'),
                'update' => $can('letters.templet.update'),
                'delete' => $can('letters.templet.delete'),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Court/LetterCategories/Form', ['category' => null]);
    }

    public function store(Request $request)
    {
        $data = $this->validateCategory($request);

        LetterCategory::create($data);

        return redirect()
            ->route('court.letter-categories.index')
            ->with('success', [
                'key' => 'court::messages.success.created',
                'replace' => ['resource' => __('court::messages.resources.letter_category')],
            ]);
    }

    public function edit(LetterCategory $letterCategory): Response
    {
        return Inertia::render('Court/LetterCategories/Form', ['category' => $this->row($letterCategory)]);
    }

    public function update(Request $request, LetterCategory $letterCategory)
    {
        $data = $this->validateCategory($request, $letterCategory->id);

        $letterCategory->update($data);

        return redirect()
            ->route('court.letter-categories.index')
            ->with('success', [
                'key' => 'court::messages.success.updated',
                'replace' => ['resource' => __('court::messages.resources.letter_category')],
            ]);
    }

    public function destroy(LetterCategory $letterCategory)
    {
        $letterCategory->delete();

        return redirect()
            ->route('court.letter-categories.index')
            ->with('success', [
                'key' => 'court::messages.success.deleted',
                'replace' => ['resource' => __('court::messages.resources.letter_category')],
            ]);
    }

    /** @return array{id: int, name: string, description: ?string} */
    private function row(LetterCategory $category): array
    {
        return [
            'id' => (int) $category->id,
            'name' => (string) $category->name,
            'description' => $category->description ?: null,
        ];
    }

    private function validateCategory(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:court_letter_categories,name'.($ignoreId ? ",{$ignoreId}" : '')],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
