<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\OrganizationDesign\DesignOverviewService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The module's landing page: category cards for what the user may open,
 * figures from real data, and "what applied on date X" for one
 * organization. Open to anyone holding any permission of the module; each
 * card and figure is still cut to the user's grants and scope.
 */
class DesignOverviewController extends Controller
{
    /** Category card => the permissions any one of which opens it. */
    public const CATEGORIES = [
        'legacy' => ['route' => 'organization-design.legacy-baselines.index', 'any' => ['legacy-baselines.viewAny']],
        'legal' => ['route' => 'organization-design.legal-sources.index', 'any' => ['legal-sources.viewAny', 'institution-mandates.viewAny']],
        'functions_services' => ['route' => 'organization-design.services.index', 'any' => ['service-catalog.viewAny', 'organization-functions.viewAny']],
        'processes' => ['route' => 'organization-design.processes.index', 'any' => ['processes.viewAny']],
        'occupations' => ['route' => 'organization-design.competency-catalog.index', 'any' => ['competency-catalog.viewAny', 'competency-catalog.manage', 'occupations.viewAny', 'professions.manage']],
        'unit_design' => ['route' => 'organization-design.studies.index', 'any' => ['organization-design.viewAny']],
        'structure' => ['route' => 'organization-design.structure.index', 'any' => ['organization-design.viewAny']],
        'workforce' => ['route' => 'organization-design.manpower-plans.index', 'any' => ['workforce-planning.viewAny']],
        'positions' => ['route' => 'organization-design.position-design.index', 'any' => ['position-profiles.viewAny', 'job-descriptions.viewAny']],
        'imports' => ['route' => 'organization-design.imports.index', 'any' => ['design-imports.viewAny']],
        'validation' => ['route' => 'organization-design.mapping.index', 'any' => ['data-mapping.view']],
        'reports' => ['route' => 'organization-design.reports.index', 'any' => ['organization-design-reports.view']],
    ];

    public function __construct(private readonly DesignOverviewService $overview, private readonly OrganizationScopeService $scope) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $categories = collect(self::CATEGORIES)->filter(fn (array $c) => collect($c['any'])->contains(fn (string $p) => $user->can($p)));
        abort_if($categories->isEmpty(), 403);

        $organizations = Organization::query()->orderBy('name_en');
        $this->scope->applyOrganizationScope($organizations, $user, 'id');
        $options = $organizations->limit(500)->get(['id', 'code', 'name_en', 'name_am']);

        $organizationId = $request->string('organization_id')->toString() ?: null;
        if ($organizationId !== null && ! $this->scope->canAccess($user, $organizationId)) {
            abort(403);
        }
        $date = $request->string('as_of')->toString();
        $asOf = $organizationId !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $this->overview->asOf($organizationId, $date) : null;

        return Inertia::render('OrganizationDesign/Overview', [
            'categories' => $categories->map(fn (array $c, string $key) => ['key' => $key, 'href' => route($c['route'])])->values(),
            'kpis' => $this->overview->kpis($user, $organizationId),
            'organizations' => $options->map->only(['id', 'code', 'name_en', 'name_am']),
            'filters' => ['organization_id' => $organizationId, 'as_of' => $date ?: null],
            'asOf' => $asOf,
        ]);
    }
}
