<?php

declare(strict_types=1);

namespace App\Http\Controllers\Performance;

use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-side, scoped, paginated search for large selectors (300,000+
 * employees): nothing is ever loaded wholesale into a dropdown.
 */
class PerformanceLookupController extends PerformanceController
{
    private const LIMIT = 20;

    /** The pickers only exist on EPMS management screens; scope still filters every row. */
    private const PERMISSIONS = [
        'performance_cycles.create', 'performance_plans.create', 'performance_plans.update',
        'kpis.create', 'kpis.update', 'employee_performance_agreements.manage', 'performance_calibration.manage',
    ];

    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function organizations(Request $request): JsonResponse
    {
        $this->authorizeLookup($request);
        $q = $this->term($request);

        return response()->json($this->scope->applyOrganizationScope(Organization::query(), $request->user(), 'id')
            ->when($q !== '', fn ($query) => $query->where(fn ($s) => $s->where('name_en', 'like', "%{$q}%")->orWhere('name_am', 'like', "%{$q}%")))
            ->orderBy('name_en')->limit(self::LIMIT)->get(['id', 'name_en', 'name_am']));
    }

    public function units(Request $request): JsonResponse
    {
        $this->authorizeLookup($request);
        $organizationId = (string) $request->query('organization_id');
        abort_unless($this->scope->canAccessOrganization($request->user(), $organizationId), 403);
        $q = $this->term($request);

        return response()->json(OrganizationUnit::query()->where('organization_id', $organizationId)
            ->when($q !== '', fn ($query) => $query->where(fn ($s) => $s->where('name_en', 'like', "%{$q}%")->orWhere('name_am', 'like', "%{$q}%")))
            ->orderBy('name_en')->limit(self::LIMIT)->get(['id', 'name_en', 'name_am', 'parent_unit_id']));
    }

    public function positions(Request $request): JsonResponse
    {
        $this->authorizeLookup($request);
        $organizationId = (string) $request->query('organization_id');
        abort_unless($this->scope->canAccessOrganization($request->user(), $organizationId), 403);
        $q = $this->term($request);

        return response()->json(Position::query()->where('organization_id', $organizationId)
            ->when($request->query('organization_unit_id'), fn ($query, $unit) => $query->where('organization_unit_id', $unit))
            ->when($q !== '', fn ($query) => $query->where(fn ($s) => $s->where('title_en', 'like', "%{$q}%")->orWhere('title_am', 'like', "%{$q}%")->orWhere('job_position_code', 'like', "%{$q}%")))
            ->orderBy('title_en')->limit(self::LIMIT)->get(['id', 'title_en', 'title_am', 'job_position_code', 'organization_unit_id']));
    }

    /** Employees with their assignments, inside the user's organization scope only. */
    public function employees(Request $request): JsonResponse
    {
        $this->authorizeLookup($request);
        $q = $this->term($request);
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $allowed = $this->scope->isUnrestricted($request->user()) ? null : $this->scope->allowedOrganizationIds($request->user());
        $assignments = EmployeeAssignment::query()
            ->with(['employee:id,full_name,name_en,employee_number', 'organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am', 'position:id,title_en,title_am'])
            ->when($allowed !== null, fn ($query) => $query->whereIn('organization_id', $allowed))
            ->whereHas('employee', fn ($e) => $e->where('employee_number', 'like', "%{$q}%")->orWhere('full_name', 'like', "%{$q}%")->orWhere('name_en', 'like', "%{$q}%"))
            ->orderByDesc('is_current')->limit(self::LIMIT)->get();

        return response()->json($assignments->map(fn (EmployeeAssignment $a) => [
            'assignment_id' => $a->getKey(), 'employee_id' => $a->employee_id, 'is_current' => (bool) $a->is_current,
            'name' => $a->employee?->full_name, 'name_en' => $a->employee?->name_en, 'number' => $a->employee?->employee_number,
            'organization' => $a->organization?->name_en, 'unit' => $a->organizationUnit?->name_en, 'position' => $a->position?->title_en,
            'effective_from' => $a->effective_from?->toDateString(), 'effective_to' => $a->effective_to?->toDateString(),
        ]));
    }

    private function authorizeLookup(Request $request): void
    {
        $this->ensureEnabled();
        abort_unless($request->user()?->canAny(self::PERMISSIONS), 403);
    }

    private function term(Request $request): string
    {
        return trim(mb_substr((string) $request->query('q', ''), 0, 100));
    }
}
