<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\ReportingLines;

use App\Enums\OccupantBasis;
use App\Enums\TemporaryAuthorityStatus;
use App\Http\Controllers\Controller;
use App\Models\ActingAppointment;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Position;
use App\Models\SupervisorDelegation;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ReportingLines\Occupant;
use App\Services\ReportingLines\OccupantResolver;
use App\Services\ReportingLines\PositionReportingGraph;
use App\Services\ReportingLines\ReportingDiagnostics;
use App\Services\ReportingLines\ReportingGraphRepository;
use App\Services\ReportingLines\ReportingLinePresenter;
use App\Services\ReportingLines\ReportingLineResolver;
use App\Services\ReportingLines\UnitNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reporting lines (ADR 0025): the organization overview with its integrity
 * report, and one employee's supervisor, chain and reports. Every page
 * re-checks the permission and the organization scope on the server; the
 * employee page also needs the viewer to be the employee, their supervisor
 * or a reader of reporting lines in scope.
 */
class ReportingHierarchyController extends Controller
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly ReportingGraphRepository $graphs,
        private readonly OccupantResolver $occupants,
        private readonly ReportingDiagnostics $diagnostics,
        private readonly ReportingLineResolver $resolver,
        private readonly ReportingLinePresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('reportingLinesViewAny');
        $user = $request->user();

        $organizations = $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')
            ->where('status', 'active')
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_am', 'code']);

        $requestedId = $request->string('organization_id')->toString();
        $selectedId = $requestedId ?: ($organizations->first()?->id);
        $selected = $selectedId ? $organizations->firstWhere('id', $selectedId) : null;
        // An organization named outside the scope is refused, not silently swapped for another.
        abort_if($requestedId !== '' && $selected === null, 403);
        $asOf = $this->asOf($request);

        $overview = null;
        if ($selected !== null) {
            $this->authorize('reportingLinesViewOrganization', $selected);
            $overview = $this->overview($selected, $asOf);
        }

        return Inertia::render('ReportingLines/Index', [
            'organizations' => $organizations->map(fn (Organization $o): array => ['id' => (string) $o->id, 'name_en' => $o->name_en, 'name_am' => $o->name_am, 'code' => $o->code])->values()->all(),
            'selected_organization_id' => $selected?->id,
            'as_of' => $asOf,
            'overview' => $overview,
            'structural_reporting_enabled' => (bool) config('features.structural_reporting'),
            'can' => [
                'manage' => $user->can('reporting-lines.manage'),
                'edit_positions' => $user->can('positions.update'),
            ],
        ]);
    }

    public function employee(Request $request, Employee $employee): Response
    {
        $this->authorize('reportingLinesViewLine', $employee);
        $user = $request->user();
        $asOf = $this->asOf($request);
        $employee->load(['currentAssignment.position', 'currentAssignment.organizationUnit', 'currentAssignment.organization']);

        $canViewTeam = $user->can('reportingLinesViewTeam', $employee);
        $reports = null;
        $indirect = null;
        if ($canViewTeam) {
            $reports = $this->presenter->employeePage($this->resolver->directReportsQuery($employee, $asOf), 25);
            $all = $this->resolver->allReportsQuery($employee, $asOf);
            $indirect = ['total' => max(0, $all['query']->count() - $reports['meta']['total']), 'truncated' => $all['truncated']];
        }

        $canSeeAuthority = $user->can('reporting-lines.view_any') || $user->can('reporting-lines.manage') || $canViewTeam;

        return Inertia::render('ReportingLines/Employee', [
            'employee' => $this->presenter->employee($employee),
            'as_of' => $asOf,
            'supervisor' => $this->presenter->resolution($this->resolver->directSupervisorOf($employee, null, $asOf)),
            'chain' => $this->presenter->chain($this->resolver->chainOf($employee, null, $asOf)),
            'reports' => $reports,
            'indirect' => $indirect,
            'acting_appointments' => $canSeeAuthority ? ActingAppointment::query()->where('employee_id', $employee->getKey())->with(['position.organizationUnit', 'employee', 'organization', 'authorizer', 'revoker'])->latest('starts_on')->limit(20)->get()->map(fn (ActingAppointment $a): array => $this->presenter->actingAppointment($a))->all() : [],
            'delegations' => $canSeeAuthority ? SupervisorDelegation::query()->where(fn ($q) => $q->where('delegator_employee_id', $employee->getKey())->orWhere('delegate_employee_id', $employee->getKey()))->with(['delegator', 'delegate', 'organization', 'authorizer', 'revoker'])->latest('starts_on')->limit(20)->get()->map(fn (SupervisorDelegation $d): array => $this->presenter->delegation($d))->all() : [],
            'can' => [
                'view_team' => $canViewTeam,
                'manage' => $user->can('reporting-lines.manage') && $this->scope->canAccessEmployee($user, $employee),
                'view_employee' => $user->can('view', $employee),
            ],
        ]);
    }

    /** Employees of an organization in scope, for the acting and delegation forms. Name, number and position only. */
    public function lookupEmployees(Request $request): JsonResponse
    {
        $organization = Organization::query()->findOrFail($request->string('organization_id')->toString());
        $this->authorize('reportingLinesViewOrganization', $organization);
        $term = trim($request->string('q')->toString());

        $rows = Employee::query()
            ->where('status', 'active')
            ->whereHas('currentAssignment', fn ($q) => $q->where('organization_id', $organization->getKey())->where('is_current', true))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('full_name', ci_like_operator(), "%{$term}%")
                ->orWhere('employee_number', ci_like_operator(), "%{$term}%")))
            ->with(['currentAssignment.position:id,title_en,title_am', 'currentAssignment.organizationUnit:id,name_en,name_am'])
            ->orderBy('full_name')
            ->limit(20)
            ->get();

        return response()->json(['data' => $rows->map(fn (Employee $e): array => [
            'id' => (string) $e->id,
            'employee_number' => $e->employee_number,
            'full_name' => $e->full_name,
            'name_am' => $e->fullNameAm(),
            'position_title_en' => $e->currentAssignment?->position?->title_en,
            'position_title_am' => $e->currentAssignment?->position?->title_am,
            'unit_name_en' => $e->currentAssignment?->organizationUnit?->name_en,
            'unit_name_am' => $e->currentAssignment?->organizationUnit?->name_am,
        ])->values()->all()]);
    }

    /** Active positions of an organization in scope, heads first, for the acting form. */
    public function lookupPositions(Request $request): JsonResponse
    {
        $organization = Organization::query()->findOrFail($request->string('organization_id')->toString());
        $this->authorize('reportingLinesViewOrganization', $organization);

        $rows = Position::query()
            ->where('organization_id', $organization->getKey())
            ->where('is_active', true)
            ->with('organizationUnit:id,name_en,name_am')
            ->orderByDesc('is_unit_head')
            ->orderBy('title_en')
            ->limit(500)
            ->get(['id', 'title_en', 'title_am', 'job_position_code', 'is_unit_head', 'organization_unit_id']);

        return response()->json(['data' => $rows->map(fn (Position $p): array => [
            'id' => (string) $p->id,
            'title_en' => $p->title_en,
            'title_am' => $p->title_am,
            'code' => $p->job_position_code,
            'is_unit_head' => (bool) $p->is_unit_head,
            'unit_name_en' => $p->organizationUnit?->name_en,
            'unit_name_am' => $p->organizationUnit?->name_am,
        ])->values()->all()]);
    }

    /** @return array<string, mixed> */
    private function overview(Organization $organization, string $asOf): array
    {
        $graph = $this->graphs->forOrganization((string) $organization->getKey(), $asOf);
        $units = array_filter($graph->units(), fn (UnitNode $u): bool => $u->isActive);

        $headIds = [];
        foreach ($units as $unit) {
            [$head] = $graph->headOfUnit($unit->id);
            if ($head !== null) {
                $headIds[] = $head->id;
            }
        }
        [$orgHead, $severalOrgHeads] = $graph->organizationHead();
        if ($orgHead !== null) {
            $headIds[] = $orgHead->id;
        }
        $occupants = $this->occupants->occupantsOf($headIds, $asOf);

        $describeHolders = function (?string $positionId) use ($occupants): array {
            $holders = $positionId === null ? [] : ($occupants[$positionId] ?? []);
            $active = array_values(array_filter($holders, fn (Occupant $o): bool => $o->employeeActive));
            $acting = array_values(array_filter($active, fn (Occupant $o): bool => $o->basis === OccupantBasis::Acting));
            $substantive = array_values(array_filter($active, fn (Occupant $o): bool => $o->basis === OccupantBasis::Substantive));
            $state = count($acting) > 1 || count($substantive) > 1 ? 'ambiguous' : (count($acting) === 1 ? 'acting' : (count($substantive) === 1 ? 'held' : 'vacant'));

            return [
                'state' => $state,
                'holders' => array_map(fn (Occupant $o): array => [
                    'employee_id' => $o->employeeId, 'employee_number' => $o->employeeNumber, 'name' => $o->name, 'name_am' => $o->nameAm,
                    'basis' => $o->basis->value, 'basis_label' => $o->basis->label(),
                ], $active),
            ];
        };

        $unitRows = [];
        foreach ($units as $unit) {
            [$head, $several] = $graph->headOfUnit($unit->id);
            $parent = $unit->parentId !== null ? $graph->unit($unit->parentId) : null;
            $link = $head !== null ? $graph->supervisorOf($head->id) : null;
            $unitRows[] = [
                'id' => $unit->id,
                'code' => $unit->code,
                'name_en' => $unit->nameEn,
                'name_am' => $unit->nameAm,
                'parent_id' => $unit->parentId,
                'parent_name_en' => $parent?->nameEn,
                'parent_name_am' => $parent?->nameAm,
                'depth' => $this->depth($graph, $unit),
                'head' => $head === null ? null : [
                    'position_id' => $head->id, 'title_en' => $head->titleEn, 'title_am' => $head->titleAm, 'code' => $head->jobPositionCode,
                    'active' => $head->isActiveOnDate,
                    'reports_to' => $link !== null && $link->isResolved() ? [
                        'position_id' => $link->supervisorPositionId,
                        'title_en' => $graph->position((string) $link->supervisorPositionId)?->titleEn ?? $graph->parentOrganization()->headPosition?->titleEn,
                        'basis' => $link->basis?->value, 'basis_label' => $link->basis?->label(),
                    ] : null,
                    'link_outcome' => $link?->outcome->value,
                    'link_outcome_label' => $link?->outcome->label(),
                    'link_explanation' => $link === null ? null : (string) __($link->explanation, $link->explanationParams),
                ],
                'several_heads' => $several,
                'holders' => $describeHolders($head?->id),
                'state' => $several ? 'several_heads' : ($head === null ? 'missing' : (! $head->isActiveOnDate ? 'inactive' : $describeHolders($head->id)['state'])),
            ];
        }
        usort($unitRows, fn (array $a, array $b): int => [$a['depth'], $a['name_en']] <=> [$b['depth'], $b['name_en']]);

        $orgHeadLink = $orgHead !== null ? $graph->supervisorOf($orgHead->id) : null;

        return [
            'organization_head' => [
                'position' => $orgHead === null ? null : ['position_id' => $orgHead->id, 'title_en' => $orgHead->titleEn, 'title_am' => $orgHead->titleAm, 'code' => $orgHead->jobPositionCode, 'active' => $orgHead->isActiveOnDate],
                'several' => $severalOrgHeads,
                'holders' => $describeHolders($orgHead?->id),
                'state' => $severalOrgHeads ? 'several_heads' : ($orgHead === null ? 'missing' : (! $orgHead->isActiveOnDate ? 'inactive' : $describeHolders($orgHead->id)['state'])),
                'reports_to' => $orgHeadLink === null ? null : [
                    'outcome' => $orgHeadLink->outcome->value,
                    'outcome_label' => $orgHeadLink->outcome->label(),
                    'explanation' => (string) __($orgHeadLink->explanation, $orgHeadLink->explanationParams),
                    'organization_name_en' => $graph->parentOrganization()->organizationNameEn,
                    'organization_name_am' => $graph->parentOrganization()->organizationNameAm,
                    'position_title_en' => $graph->parentOrganization()->headPosition?->titleEn,
                ],
            ],
            'units' => $unitRows,
            'diagnostics' => $this->diagnostics->forOrganization($organization, $asOf),
            'acting_active' => ActingAppointment::query()->where('organization_id', $organization->getKey())->inForceOn($asOf)->count(),
            'delegations_active' => SupervisorDelegation::query()->where('organization_id', $organization->getKey())->inForceOn($asOf)->count(),
            'acting_total' => ActingAppointment::query()->where('organization_id', $organization->getKey())->where('status', TemporaryAuthorityStatus::Active->value)->count(),
        ];
    }

    private function depth(PositionReportingGraph $graph, UnitNode $unit): int
    {
        $depth = 0;
        $current = $unit;
        while ($current->parentId !== null && $depth < 32) {
            $current = $graph->unit($current->parentId);
            if ($current === null) {
                break;
            }
            $depth++;
        }

        return $depth;
    }

    private function asOf(Request $request): string
    {
        $value = $request->string('as_of')->toString();
        if ($value === '') {
            return today()->toDateString();
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return today()->toDateString();
        }
    }
}
