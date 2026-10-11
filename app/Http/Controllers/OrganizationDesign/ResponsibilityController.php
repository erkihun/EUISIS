<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\CatalogEntryRequest;
use App\Http\Requests\OrganizationDesign\ResponsibilitySyncRequest;
use App\Models\Organization;
use App\Models\OrganizationDesignStudy;
use App\Models\ResponsibilityType;
use App\Services\OrganizationDesign\OrganizationDesignAudit;
use App\Services\OrganizationDesign\ResponsibilityService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The responsibility matrix of an organization, the editor per unit, and the responsibility-type catalog. */
class ResponsibilityController extends Controller
{
    public function __construct(private readonly ResponsibilityService $responsibilities, private readonly OrganizationScopeService $scope, private readonly OrganizationDesignAudit $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', OrganizationDesignStudy::class);
        $user = $request->user();
        $organizations = Organization::query()->orderBy('name_en');
        $this->scope->applyOrganizationScope($organizations, $user, 'id');
        $options = $organizations->limit(500)->get(['id', 'code', 'name_en', 'name_am']);
        $organizationId = $request->string('organization_id')->toString() ?: ($options->count() === 1 ? $options->first()->id : null);
        if ($organizationId !== null) {
            $this->authorize('viewMatrix', [OrganizationDesignStudy::class, $organizationId]);
        }

        return Inertia::render('OrganizationDesign/Responsibilities/Index', [
            'organizationId' => $organizationId,
            'organizations' => $options->map->only(['id', 'code', 'name_en', 'name_am']),
            'matrix' => $organizationId ? $this->responsibilities->matrix($organizationId) : [],
            'gaps' => $organizationId ? $this->responsibilities->gaps($organizationId) : ['functions' => [], 'services' => [], 'processes' => []],
            'objects' => $organizationId ? $this->responsibilities->objects($organizationId) : ['function' => [], 'service' => [], 'process' => [], 'activity' => []],
            'types' => ResponsibilityType::query()->orderBy('sort_order')->orderBy('code')->get()->map->only(['id', 'code', 'name_en', 'name_am', 'description_en', 'description_am', 'is_exclusive', 'covers_object', 'is_active', 'sort_order']),
            'can' => [
                'map' => $organizationId !== null && $user->can('organization-design.update') && $this->scope->canAccess($user, $organizationId),
                'manageTypes' => $user->can('responsibility-types.manage'),
            ],
        ]);
    }

    public function sync(ResponsibilitySyncRequest $request): RedirectResponse
    {
        $this->responsibilities->sync($request->user(), $request->subject(), $request->validated('items'));

        return back()->with('flash', ['message' => __('organization-design.responsibilities_saved'), 'type' => 'success']);
    }

    public function storeType(CatalogEntryRequest $request): RedirectResponse
    {
        $type = ResponsibilityType::query()->create($request->validated());
        $this->audit->record(AuditEventType::ResponsibilityTypeSaved, $request->user(), $type, $type->only(['code', 'name_en', 'is_exclusive', 'covers_object', 'is_active']));

        return back()->with('flash', ['message' => __('organization-design.catalog_saved'), 'type' => 'success']);
    }

    public function updateType(CatalogEntryRequest $request, ResponsibilityType $responsibilityType): RedirectResponse
    {
        $old = $responsibilityType->only(['code', 'name_en', 'name_am', 'is_exclusive', 'covers_object', 'is_active', 'sort_order']);
        $responsibilityType->fill($request->validated())->save();
        $this->audit->record(AuditEventType::ResponsibilityTypeSaved, $request->user(), $responsibilityType, $responsibilityType->only(['code', 'name_en', 'name_am', 'is_exclusive', 'covers_object', 'is_active', 'sort_order']), $old);

        return back()->with('flash', ['message' => __('organization-design.catalog_saved'), 'type' => 'success']);
    }
}
