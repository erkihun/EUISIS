<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\InstitutionOffices\CreateInstitutionOfficeAction;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationUnitStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\InstitutionOffices\StoreInstitutionOfficeRequest;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType as OrganizationUnitTypeModel;
use App\Services\OrganizationUnits\OrganizationUnitTreeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * @deprecated The Institution Offices module is deprecated.
 *             All offices are now managed as Organization Units.
 *             GET routes redirect to organization-units.* equivalents.
 *             POST /institution-offices (store) remains functional for backward compatibility.
 */
class InstitutionOfficeController extends Controller
{
    /**
     * @deprecated Redirects to organization-units.index.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('organization-units.index', [], 301);
    }

    /**
     * Render the create form (still active — form POSTs to institution-offices.store).
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', OrganizationUnit::class);

        $organizationId = $request->string('organization_id')->toString()
            ?: $request->string('institution_id')->toString()
            ?: null;

        $selectedOrganization = $organizationId !== null
            ? Organization::query()->find($organizationId, ['id', 'name_en', 'name_am', 'code'])
            : null;

        $parentUnits = [];

        if ($selectedOrganization !== null) {
            $parentUnits = app(OrganizationUnitTreeService::class)->optionsForOrganization($selectedOrganization->id);
        }

        return Inertia::render('InstitutionOffices/Create', [
            'institutions' => Organization::query()->orderBy('name_en')->get(['id', 'name_en', 'name_am', 'code']),
            'selectedInstitution' => $selectedOrganization,
            'parentOfficeOptions' => $parentUnits,
            'geographicOrgs' => [],
            'unitTypes' => OrganizationUnitTypeModel::query()
                ->where('is_active', true)
                ->orderByRaw("case when code = 'office' then 0 when code = 'internal_office' then 1 else 2 end")
                ->orderBy('sort_order')
                ->orderBy('name_en')
                ->get(['id', 'code', 'name_en', 'name_am']),
            'relationshipTypeOptions' => $this->secondaryRelationshipTypeOptions(),
            'levelOptions' => [],
            'statusOptions' => array_map(
                fn (OrganizationUnitStatus $c) => ['value' => $c->value, 'label' => ucfirst($c->value)],
                OrganizationUnitStatus::cases(),
            ),
        ]);
    }

    /**
     * Store — creates an OrganizationUnit via the already-migrated action.
     */
    public function store(
        StoreInstitutionOfficeRequest $request,
        CreateInstitutionOfficeAction $action,
    ): RedirectResponse {
        $unit = $action->execute($request->validated(), $request->user(), $request);

        return to_route('organization-units.show', $unit)
            ->with('flash', ['message' => __('institution-offices.messages.created'), 'type' => 'success']);
    }

    /**
     * @deprecated Looks up the organization unit mapped via institution_office_id and redirects.
     *             Falls back to organization-units.index if not found.
     */
    public function show(string $institutionOffice): RedirectResponse
    {
        $unit = OrganizationUnit::query()
            ->where('institution_office_id', $institutionOffice)
            ->first();

        if ($unit !== null) {
            return redirect()->route('organization-units.show', $unit, 301);
        }

        return redirect()->route('organization-units.index', [], 301);
    }

    /**
     * @deprecated Redirects to the mapped organization unit's edit page.
     */
    public function edit(string $institutionOffice): RedirectResponse
    {
        $unit = OrganizationUnit::query()
            ->where('institution_office_id', $institutionOffice)
            ->first();

        if ($unit !== null) {
            return redirect()->route('organization-units.edit', $unit, 301);
        }

        return redirect()->route('organization-units.index', [], 301);
    }

    /**
     * @deprecated Redirects to organization-units.update equivalent.
     */
    public function update(Request $request, string $institutionOffice): RedirectResponse
    {
        $unit = OrganizationUnit::query()
            ->where('institution_office_id', $institutionOffice)
            ->first();

        if ($unit !== null) {
            return redirect()->route('organization-units.edit', $unit);
        }

        return redirect()->route('organization-units.index');
    }

    /**
     * @deprecated Redirects to organization-units.index.
     */
    public function destroy(Request $request, string $institutionOffice): RedirectResponse
    {
        return redirect()->route('organization-units.index');
    }

    /**
     * @deprecated Redirects to organization-units.index.
     */
    public function restore(Request $request, string $institutionOffice): RedirectResponse
    {
        return redirect()->route('organization-units.index');
    }

    /**
     * @deprecated Redirects to organization-units.index.
     */
    public function move(Request $request, string $institutionOffice): RedirectResponse
    {
        return redirect()->route('organization-units.index');
    }

    private function secondaryRelationshipTypeOptions(): array
    {
        return collect([
            OrganizationRelationshipType::FunctionalReporting,
            OrganizationRelationshipType::TechnicalSupervision,
            OrganizationRelationshipType::AdministrativeReporting,
            OrganizationRelationshipType::Coordination,
            OrganizationRelationshipType::Oversight,
            OrganizationRelationshipType::ServiceDelivery,
            OrganizationRelationshipType::BudgetReporting,
            OrganizationRelationshipType::DottedLineReporting,
            OrganizationRelationshipType::Other,
        ])->map(fn (OrganizationRelationshipType $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ])->all();
    }
}
