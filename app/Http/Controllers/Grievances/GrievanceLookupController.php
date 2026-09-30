<?php

declare(strict_types=1);

namespace App\Http\Controllers\Grievances;

use App\Enums\AssignmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Small JSON lookups for grievance configuration and case pickers. Results
 * are minimal (id, name, number) and bounded; nothing here exposes
 * grievance data.
 */
class GrievanceLookupController extends Controller
{
    private const PERMISSIONS = [
        'grievance_committee_members.manage', 'grievance_committees.manage', 'grievance_routes.manage', 'grievance_sla.manage',
        'grievance_settings.update', 'grievance_settings.manage_delegations', 'grievances.review', 'grievances.decide_recusal',
        'grievance_hearings.manage', 'grievance_correspondence.create',
    ];

    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function employees(Request $request): JsonResponse
    {
        $this->authorizeLookup($request);
        $term = trim((string) $request->string('q'));
        $organizationId = $request->string('organization_id')->value() ?: null;

        $query = Employee::query()->where('status', 'active')
            ->whereHas('currentAssignment', function ($a) use ($request, $organizationId): void {
                $a->where('assignment_status', AssignmentStatus::Active->value);
                $organizationId ? $a->where('organization_id', $organizationId) : $this->scope->applyOrganizationScope($a, $request->user());
            });
        if ($term !== '') {
            $query->where(fn ($q) => $q->where('full_name', 'like', "%{$term}%")->orWhere('name_en', 'like', "%{$term}%")->orWhere('employee_number', 'like', "%{$term}%"));
        }

        return response()->json($query->orderBy('full_name')->limit(25)->get(['id', 'full_name', 'name_en', 'employee_number'])
            ->map(fn ($e) => ['id' => $e->id, 'name' => $e->full_name, 'name_en' => $e->name_en ?: $e->full_name, 'employee_number' => $e->employee_number]));
    }

    public function units(Request $request): JsonResponse
    {
        $this->authorizeLookup($request);
        $request->validate(['organization_id' => ['required', 'uuid']]);

        return response()->json(OrganizationUnit::query()->where('organization_id', (string) $request->string('organization_id'))
            ->where('status', 'active')->orderBy('name_en')->limit(500)->get(['id', 'name_en', 'name_am', 'unit_type', 'parent_unit_id']));
    }

    public function positions(Request $request): JsonResponse
    {
        $this->authorizeLookup($request);
        $term = trim((string) $request->string('q'));
        $query = Position::query()->where('is_active', true);
        if ($request->filled('organization_id')) {
            $query->where('organization_id', (string) $request->string('organization_id'));
        }
        if ($term !== '') {
            $query->where(fn ($q) => $q->where('title_en', 'like', "%{$term}%")->orWhere('title_am', 'like', "%{$term}%")->orWhere('job_position_code', 'like', "%{$term}%"));
        }

        return response()->json($query->orderBy('title_en')->limit(25)->get(['id', 'title_en', 'title_am', 'job_position_code', 'organization_id']));
    }

    public function users(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('grievance_settings.manage_delegations'), 403);
        $term = trim((string) $request->string('q'));

        return response()->json(User::query()->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))
            ->orderBy('name')->limit(25)->get(['id', 'name']));
    }

    private function authorizeLookup(Request $request): void
    {
        $user = $request->user();
        abort_unless(collect(self::PERMISSIONS)->contains(fn (string $p) => $user->can($p)), 403);
    }
}
