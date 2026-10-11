<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Http\Controllers\Controller;
use App\Models\Competency;
use App\Models\CompetencyType;
use App\Models\IscoGroup;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\PositionService;
use App\Services\OrganizationDesign\DataQualityService;
use App\Services\OrganizationDesign\LegacyMappingService;
use App\Services\OrganizationDesign\MappingWorkspaceService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The mapping workspace: unmapped legacy records, suggestions a person applies, data quality. */
class MappingController extends Controller
{
    public function __construct(private readonly MappingWorkspaceService $mapping, private readonly DataQualityService $quality, private readonly LegacyMappingService $legacy, private readonly OrganizationScopeService $scope) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('data-mapping.view'), 403);
        $organizations = Organization::query()->orderBy('name_en');
        $this->scope->applyOrganizationScope($organizations, $user, 'id');
        $options = $organizations->limit(500)->get(['id', 'code', 'name_en', 'name_am']);
        $organizationId = $request->string('organization_id')->toString() ?: ($options->count() === 1 ? $options->first()->id : null);
        if ($organizationId !== null) {
            abort_unless($this->scope->canAccess($user, $organizationId), 403);
        }
        $canMap = $organizationId !== null && $user->can('processes.update') && $this->scope->canAccess($user, $organizationId);
        $canCatalog = $user->can('professions.manage') || $user->can('competency-catalog.manage');

        return Inertia::render('OrganizationDesign/Mapping/Index', [
            'organizationId' => $organizationId,
            'organizations' => $options->map->only(['id', 'code', 'name_en', 'name_am']),
            'unmapped' => $organizationId ? $this->mapping->unmapped($organizationId) : null,
            'legacy' => $organizationId ? ['summary' => $this->legacy->classify($organizationId)['summary']] : null,
            'quality' => $organizationId ? $this->quality->check($organizationId) : null,
            'catalog' => $canCatalog ? [
                ...$this->mapping->unmappedCatalog(),
                'iscoGroups' => IscoGroup::query()->where('is_active', true)->orderBy('code')->limit(5000)->get(['id', 'revision', 'code', 'name_en', 'name_am'])->map->only(['id', 'revision', 'code', 'name_en', 'name_am'])->values(),
                'competencyTypes' => CompetencyType::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am'])->values(),
            ] : null,
            'can' => [
                'map' => $canMap,
                'apply' => $canMap,
                'catalog' => $canCatalog,
                'occupations' => $user->can('professions.manage'),
                'competencies' => $user->can('competency-catalog.manage'),
            ],
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['organization_id' => ['required', 'uuid', 'exists:organizations,id']]);
        abort_unless($user->can('processes.update') && $this->scope->canAccess($user, $data['organization_id']), 403);
        $result = $this->mapping->applySuggestions($user, $data['organization_id']);

        return to_route('organization-design.mapping.index', ['organization_id' => $data['organization_id']])
            ->with('flash', ['message' => __('organization-design.mapping_applied', $result), 'type' => 'success']);
    }

    public function linkOccupation(Request $request, Occupation $occupation): RedirectResponse
    {
        abort_unless($request->user()->can('professions.manage'), 403);
        $data = $request->validate(['isco_group_id' => ['nullable', 'uuid']]);
        $this->mapping->linkOccupation($request->user(), $occupation, $data['isco_group_id'] ?? null);

        return back()->with('flash', ['message' => __('organization-design.occupation_linked'), 'type' => 'success']);
    }

    public function linkPositionService(Request $request, PositionService $positionService): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('processes.update') && $this->scope->canAccess($user, $positionService->organization_id), 403);
        $data = $request->validate(['master_service_id' => ['nullable', 'uuid']]);
        $this->mapping->linkPositionService($user, $positionService, $data['master_service_id'] ?? null);

        return back()->with('flash', ['message' => __('organization-design.position_service_linked'), 'type' => 'success']);
    }

    public function linkCompetency(Request $request, Competency $competency): RedirectResponse
    {
        abort_unless($request->user()->can('competency-catalog.manage'), 403);
        $data = $request->validate(['competency_type_id' => ['nullable', 'uuid']]);
        $this->mapping->linkCompetency($request->user(), $competency, $data['competency_type_id'] ?? null);

        return back()->with('flash', ['message' => __('organization-design.competency_linked'), 'type' => 'success']);
    }
}
