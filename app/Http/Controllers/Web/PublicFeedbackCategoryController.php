<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicFeedbackCategoryRequest;
use App\Models\Organization;
use App\Models\PublicFeedbackCategory;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ServiceFeedback\PublicFeedbackCategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Management of public feedback categories (brief §31–33). City-wide
 * categories belong to city-level users; an institution's own categories to
 * the administrators in its scope. A category is created and edited on its
 * own page, like every other record.
 */
class PublicFeedbackCategoryController extends Controller
{
    public function __construct(
        private readonly PublicFeedbackCategoryService $categories,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user();
        abort_unless($user->hasAnyPermission([PublicFeedbackCategoryService::PERMISSION]), 403);

        // The same filter bar as every other list: search and status, kept in the query string.
        $status = $request->string('status')->toString();
        $filters = [
            'search' => $request->string('search')->trim()->toString(),
            'status' => in_array($status, ['active', 'inactive'], true) ? $status : '',
        ];

        return Inertia::render('ServiceFeedback/Categories/Index', [
            'filters' => $filters,
            'categories' => $this->categories->queryFor($user)
                ->when($filters['search'] !== '', function ($query) use ($filters): void {
                    $search = '%'.$filters['search'].'%';
                    $query->where(fn ($q) => $q
                        ->where('code', ci_like_operator(), $search)
                        ->orWhere('name_en', ci_like_operator(), $search)
                        ->orWhere('name_am', ci_like_operator(), $search));
                })
                ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
                ->get()->map(fn (PublicFeedbackCategory $category): array => $this->present($category, $user))->values()->all(),
            ...$this->formOptions($user),
        ]);
    }

    public function create(): Response
    {
        $user = $this->user();
        abort_unless($user->hasAnyPermission([PublicFeedbackCategoryService::PERMISSION]), 403);

        $options = $this->formOptions($user);
        abort_unless($options['canManageCityWide'] || $options['organizations'] !== [], 403);

        return Inertia::render('ServiceFeedback/Categories/Create', $options);
    }

    public function edit(PublicFeedbackCategory $category): Response
    {
        $user = $this->user();
        abort_unless($this->categories->canManage($user, $category->organization_id), 403);

        return Inertia::render('ServiceFeedback/Categories/Edit', [
            'category' => $this->present($category, $user),
            ...$this->formOptions($user),
        ]);
    }

    public function store(StorePublicFeedbackCategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        abort_unless($this->categories->canManage($this->user(), $data['organization_id'] ?? null), 403);

        $this->categories->store($this->user(), $data, $request);

        return redirect()->route('service-feedback.categories.index')->with('success', __('service-feedback.category_saved'));
    }

    public function update(StorePublicFeedbackCategoryRequest $request, PublicFeedbackCategory $category): RedirectResponse
    {
        $data = $request->validated();
        $user = $this->user();

        // Both the current scope and the requested one must be the user's.
        abort_unless($this->categories->canManage($user, $category->organization_id), 403);
        abort_unless($this->categories->canManage($user, $data['organization_id'] ?? null), 403);

        $this->categories->update($user, $category, $data, $request);

        return redirect()->route('service-feedback.categories.index')->with('success', __('service-feedback.category_saved'));
    }

    public function destroy(Request $request, PublicFeedbackCategory $category): RedirectResponse
    {
        abort_unless($this->categories->canManage($this->user(), $category->organization_id), 403);

        $outcome = $this->categories->destroy($this->user(), $category, $request);

        return redirect()->route('service-feedback.categories.index')
            ->with('success', __($outcome === 'retired' ? 'service-feedback.category_retired' : 'service-feedback.category_deleted'));
    }

    /** @return array<string, mixed> */
    private function present(PublicFeedbackCategory $category, User $user): array
    {
        return [
            'id' => $category->id,
            'code' => $category->code,
            'name_en' => $category->name_en,
            'name_am' => $category->name_am,
            'description_en' => $category->description_en,
            'description_am' => $category->description_am,
            'submission_types' => $category->submission_types,
            'target_types' => $category->target_types,
            'organization_id' => $category->organization_id,
            'organization_name_en' => $category->organization?->name_en,
            'organization_name_am' => $category->organization?->name_am,
            'is_active' => $category->is_active,
            'sort_order' => $category->sort_order,
            'is_referenced' => $category->isReferenced(),
            'can_manage' => $this->categories->canManage($user, $category->organization_id),
        ];
    }

    /** The scopes a user may file a category under. @return array{organizations: list<array<string, mixed>>, canManageCityWide: bool} */
    private function formOptions(User $user): array
    {
        $organizations = $this->scope->isScopedOrganizationalAdmin($user)
            ? Organization::query()->whereIn('id', $this->scope->allowedOrganizationIds($user))
            : Organization::query()->where('status', 'active');

        return [
            'organizations' => $organizations->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_am'])->toArray(),
            'canManageCityWide' => $this->categories->canManageCityWide($user),
        ];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
