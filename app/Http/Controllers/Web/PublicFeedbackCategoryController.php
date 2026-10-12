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
 * the administrators in its scope.
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

        $organizations = $this->scope->isScopedOrganizationalAdmin($user)
            ? Organization::query()->whereIn('id', $this->scope->allowedOrganizationIds($user))
            : Organization::query()->where('status', 'active');

        return Inertia::render('ServiceFeedback/Categories/Index', [
            'categories' => $this->categories->queryFor($user)->get()->map(fn (PublicFeedbackCategory $category): array => [
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
            ])->values()->all(),
            'organizations' => $organizations->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_am'])->all(),
            'canManageCityWide' => $this->categories->canManageCityWide($user),
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

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
