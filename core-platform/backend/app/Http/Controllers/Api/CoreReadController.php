<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoreAuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Services\CoreSettings;
use App\Services\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Core workspace reads. Every list is paginated on the server, searchable,
 * and limited to the reader's organization scope; responses carry identifiers
 * and names, never personal identifiers or credential secrets.
 */
class CoreReadController extends Controller
{
    public function __construct(private readonly OrganizationScope $scope, private readonly CoreSettings $settings) {}

    /** Rows per page: Core settings › Appearance › Default Page Size. */
    private function perPage(): int
    {
        return $this->settings->int('appearance.default_page_size');
    }

    /**
     * The Core dashboard: the same metric keys as the EUISIS Core dashboard,
     * counted inside the reader's scope, and the latest audited changes.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizations = $this->scope->apply(Organization::query(), $user, 'id');
        $positions = $this->scope->apply(Position::query()->where('is_active', true), $user);
        $assignments = $this->scope->apply(EmployeeAssignment::query()->where('is_current', true)->where('assignment_status', EmployeeAssignment::ACTIVE), $user);
        $placed = (clone $assignments)->select('employee_id');
        $cards = fn (string $status): int => IdCard::query()->where('status', $status)->whereIn('employee_id', $placed)->count();
        $ids = $this->scope->organizationIds($user);

        $recent = CoreAuditLog::query()
            ->leftJoin('users', 'users.id', '=', 'core_audit_logs.actor_user_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('core_audit_logs.organization_id', $ids))
            ->orderByDesc('core_audit_logs.created_at')
            ->limit(8)
            ->get(['core_audit_logs.action', 'core_audit_logs.created_at', 'users.name as actor_name']);

        return response()->json([
            'citywide' => $ids === null,
            'organization_count' => (clone $organizations)->count(),
            'metrics' => [
                'organizations_active' => (clone $organizations)->where('status', Organization::ACTIVE)->count(),
                'units_active' => $this->scope->apply(OrganizationUnit::query()->where('status', 'active'), $user)->count(),
                'positions_active' => (clone $positions)->count(),
                'positions_held' => (clone $positions)->whereHas('holders')->count(),
                'positions_without_holder' => (clone $positions)->whereDoesntHave('holders')->count(),
                'employees_active' => Employee::query()->where('status', Employee::ACTIVE)->whereIn('id', $placed)->count(),
                'assignments_current' => (clone $assignments)->count(),
                'cards_active' => $cards(IdCard::ACTIVE),
                'cards_suspended' => $cards(IdCard::SUSPENDED),
                'cards_revoked' => $cards(IdCard::REVOKED),
            ],
            'recent' => $recent,
        ]);
    }

    public function organizations(Request $request): JsonResponse
    {
        $query = $this->scope->apply(Organization::query()->with('type:id,code,name_en,name_am'), $request->user(), 'id');
        $this->search($query, $request, ['code', 'name_en', 'name_am']);

        return response()->json($query->orderBy('name_en')->paginate($this->perPage(), ['id', 'organization_type_id', 'code', 'name_en', 'name_am', 'status', 'effective_from'])->withQueryString());
    }

    public function organization(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($this->scope->allows($request->user(), (string) $organization->id), 404);

        // Parent and children in the published hierarchy (structural edges).
        $version = HierarchyVersion::current();
        $edges = $version === null ? null : OrganizationEdge::query()->where('hierarchy_version_id', $version->id)->where('relationship_type', OrganizationEdge::STRUCTURAL);
        $parentId = $edges === null ? null : (clone $edges)->where('child_organization_id', $organization->id)->value('parent_organization_id');
        $childIds = $edges === null ? [] : (clone $edges)->where('parent_organization_id', $organization->id)->pluck('child_organization_id')->all();

        return response()->json(['data' => $organization->load('type:id,code,name_en,name_am')->only(['id', 'organization_type_id', 'code', 'name_en', 'name_am', 'status', 'effective_from', 'effective_to', 'type'])
            + [
                'units' => $organization->units()->count(),
                'positions' => $organization->positions()->count(),
                'parent' => $parentId === null ? null : Organization::query()->find($parentId, ['id', 'code', 'name_en', 'name_am']),
                'children' => Organization::query()->whereIn('id', $childIds)->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_am', 'status']),
            ]]);
    }

    public function units(Request $request): JsonResponse
    {
        $query = $this->scope->apply(OrganizationUnit::query()->with('organization:id,code,name_en'), $request->user())
            ->when($request->filled('organization_id'), fn (Builder $q) => $q->where('organization_id', $request->string('organization_id')));
        $this->search($query, $request, ['code', 'name_en', 'name_am']);

        return response()->json($query->orderBy('name_en')->paginate($this->perPage(), ['id', 'organization_id', 'organization_unit_type_id', 'parent_unit_id', 'code', 'name_en', 'name_am', 'status'])->withQueryString());
    }

    public function positions(Request $request): JsonResponse
    {
        $query = $this->scope->apply(Position::query()->with(['organization:id,code,name_en', 'unit:id,name_en'])->withCount('holders'), $request->user())
            ->when($request->filled('organization_id'), fn (Builder $q) => $q->where('organization_id', $request->string('organization_id')))
            ->when($request->input('occupancy') === 'vacant', fn (Builder $q) => $q->whereDoesntHave('holders'))
            ->when($request->input('occupancy') === 'held', fn (Builder $q) => $q->whereHas('holders'));
        $this->search($query, $request, ['job_position_code', 'title_en', 'title_am']);

        return response()->json($query->orderBy('job_position_code')->paginate($this->perPage(), ['id', 'organization_id', 'organization_unit_id', 'job_position_code', 'title_en', 'title_am', 'is_active'])->withQueryString());
    }

    public function employees(Request $request): JsonResponse
    {
        $query = Employee::query()
            ->with(['currentAssignment:id,employee_id,organization_id,position_id,assignment_status', 'currentAssignment.organization:id,code,name_en', 'currentAssignment.position:id,job_position_code,title_en'])
            ->withExists(['cards as has_active_card' => fn (Builder $q) => $q->where('status', IdCard::ACTIVE)])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')));
        $ids = $this->scope->organizationIds($request->user());
        if ($ids !== null) {
            $query->whereHas('currentAssignment', fn (Builder $q) => $q->whereIn('organization_id', $ids));
        }
        $this->search($query, $request, ['employee_number', 'full_name', 'name_en']);

        return response()->json($query->orderBy('employee_number')->paginate($this->perPage(), ['id', 'employee_number', 'full_name', 'name_en', 'gender', 'status', 'current_assignment_id'])->withQueryString());
    }

    public function employee(Request $request, Employee $employee): JsonResponse
    {
        $employee->load(['currentAssignment.organization:id,code,name_en', 'currentAssignment.position:id,job_position_code,title_en']);
        abort_unless($this->scope->allows($request->user(), $employee->currentAssignment?->organization_id), 404);

        return response()->json(['data' => [
            'employee' => $employee->only(['id', 'employee_number', 'full_name', 'name_en', 'first_name', 'middle_name', 'last_name', 'gender', 'status']),
            'current_assignment' => $employee->currentAssignment,
            'assignments' => $employee->assignments()->with(['organization:id,code,name_en', 'position:id,job_position_code,title_en'])->orderByDesc('effective_from')->get(['id', 'employee_id', 'organization_id', 'position_id', 'assignment_status', 'is_current', 'effective_from', 'effective_to', 'reason']),
            'cards' => $employee->cards()->orderByDesc('created_at')->get(['id', 'employee_id', 'card_number', 'status', 'is_current', 'issued_at', 'expires_at', 'revoked_at']),
        ]]);
    }

    public function assignments(Request $request): JsonResponse
    {
        $query = $this->scope->apply(EmployeeAssignment::query()->with(['employee:id,employee_number,full_name', 'organization:id,code,name_en', 'position:id,job_position_code,title_en']), $request->user())
            ->when(! $request->boolean('history'), fn (Builder $q) => $q->where('is_current', true));
        if ($request->filled('search')) {
            $term = '%'.addcslashes(mb_substr(trim((string) $request->input('search')), 0, 80), '%_\\').'%';
            $query->whereHas('employee', fn (Builder $q) => $q->whereLike('employee_number', $term)->orWhereLike('full_name', $term));
        }

        return response()->json($query->orderByDesc('effective_from')->paginate($this->perPage(), ['id', 'employee_id', 'organization_id', 'position_id', 'assignment_status', 'is_current', 'effective_from', 'effective_to'])->withQueryString());
    }

    public function cards(Request $request): JsonResponse
    {
        $query = IdCard::query()->with('employee:id,employee_number,full_name')
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')));
        $ids = $this->scope->organizationIds($request->user());
        if ($ids !== null) {
            $query->whereHas('employee.currentAssignment', fn (Builder $q) => $q->whereIn('organization_id', $ids));
        }
        $this->search($query, $request, ['card_number']);

        return response()->json($query->orderByDesc('created_at')->paginate($this->perPage(), ['id', 'employee_id', 'card_number', 'status', 'is_current', 'issued_at', 'expires_at'])->withQueryString());
    }

    /** Privacy-minimised: validity and the holder's number and name only. */
    public function verifyCard(Request $request): JsonResponse
    {
        $data = $request->validate(['card_uuid' => ['required', 'uuid']]);
        $card = IdCard::query()->with('employee.currentAssignment')->where('public_card_uuid', $data['card_uuid'])->first();
        if ($card === null || ! $this->scope->allows($request->user(), $card->employee?->currentAssignment?->organization_id)) {
            return response()->json(['valid' => false, 'status' => 'not_found'], 404);
        }

        return response()->json([
            'valid' => $card->isValidCredential(),
            'status' => $card->status,
            'employee_number' => $card->employee?->employee_number,
            'employee_name' => $card->employee?->full_name,
        ]);
    }

    public function audit(Request $request): JsonResponse
    {
        $query = CoreAuditLog::query()
            ->when($request->filled('action'), fn (Builder $q) => $q->where('action', $request->string('action')))
            ->when($request->filled('request_id'), fn (Builder $q) => $q->where('request_id', $request->string('request_id')));
        $ids = $this->scope->organizationIds($request->user());
        if ($ids !== null) {
            $query->whereIn('organization_id', $ids);
        }

        return response()->json($query->orderByDesc('created_at')->paginate($this->perPage(), ['id', 'actor_user_id', 'action', 'subject_type', 'subject_id', 'organization_id', 'reason', 'request_id', 'created_at'])->withQueryString());
    }

    /** @param list<string> $columns */
    private function search(Builder $query, Request $request, array $columns): void
    {
        $term = trim((string) $request->input('search', ''));
        if ($term === '') {
            return;
        }
        $like = '%'.addcslashes(mb_substr($term, 0, 80), '%_\\').'%';
        $query->where(function (Builder $q) use ($columns, $like): void {
            foreach ($columns as $column) {
                // Case-insensitive on every engine (ilike on PostgreSQL).
                $q->orWhereLike($column, $like);
            }
        });
    }
}
