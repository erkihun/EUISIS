<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoreAuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
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
    private const PER_PAGE = 25;

    public function __construct(private readonly OrganizationScope $scope) {}

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizations = $this->scope->apply(Organization::query(), $user, 'id');
        $positions = $this->scope->apply(Position::query(), $user);
        $assignments = $this->scope->apply(EmployeeAssignment::query()->where('is_current', true)->where('assignment_status', EmployeeAssignment::ACTIVE), $user);
        $placed = (clone $assignments)->select('employee_id');

        return response()->json(['metrics' => [
            'organizations' => (clone $organizations)->count(),
            'organizations_active' => (clone $organizations)->where('status', Organization::ACTIVE)->count(),
            'units' => $this->scope->apply(OrganizationUnit::query(), $user)->count(),
            'positions_active' => (clone $positions)->where('is_active', true)->count(),
            'positions_held' => (clone $positions)->whereHas('holders')->count(),
            'employees_placed' => (clone $assignments)->count(),
            'cards_active' => IdCard::query()->where('status', IdCard::ACTIVE)->whereIn('employee_id', $placed)->count(),
            'cards_suspended' => IdCard::query()->where('status', IdCard::SUSPENDED)->whereIn('employee_id', $placed)->count(),
        ]]);
    }

    public function organizations(Request $request): JsonResponse
    {
        $query = $this->scope->apply(Organization::query()->with('type:id,code,name_en,name_am'), $request->user(), 'id');
        $this->search($query, $request, ['code', 'name_en', 'name_am']);

        return response()->json($query->orderBy('name_en')->paginate(self::PER_PAGE, ['id', 'organization_type_id', 'code', 'name_en', 'name_am', 'status', 'effective_from'])->withQueryString());
    }

    public function organization(Request $request, Organization $organization): JsonResponse
    {
        abort_unless($this->scope->allows($request->user(), (string) $organization->id), 404);

        return response()->json(['data' => $organization->load('type:id,code,name_en,name_am')->only(['id', 'code', 'name_en', 'name_am', 'status', 'effective_from', 'effective_to', 'type'])
            + ['units' => $organization->units()->count(), 'positions' => $organization->positions()->count()]]);
    }

    public function units(Request $request): JsonResponse
    {
        $query = $this->scope->apply(OrganizationUnit::query()->with('organization:id,code,name_en'), $request->user())
            ->when($request->filled('organization_id'), fn (Builder $q) => $q->where('organization_id', $request->string('organization_id')));
        $this->search($query, $request, ['code', 'name_en', 'name_am']);

        return response()->json($query->orderBy('name_en')->paginate(self::PER_PAGE, ['id', 'organization_id', 'parent_unit_id', 'code', 'name_en', 'name_am', 'status'])->withQueryString());
    }

    public function positions(Request $request): JsonResponse
    {
        $query = $this->scope->apply(Position::query()->with(['organization:id,code,name_en', 'unit:id,name_en'])->withCount('holders'), $request->user())
            ->when($request->filled('organization_id'), fn (Builder $q) => $q->where('organization_id', $request->string('organization_id')))
            ->when($request->input('occupancy') === 'vacant', fn (Builder $q) => $q->whereDoesntHave('holders'))
            ->when($request->input('occupancy') === 'held', fn (Builder $q) => $q->whereHas('holders'));
        $this->search($query, $request, ['job_position_code', 'title_en', 'title_am']);

        return response()->json($query->orderBy('job_position_code')->paginate(self::PER_PAGE, ['id', 'organization_id', 'organization_unit_id', 'job_position_code', 'title_en', 'title_am', 'is_active'])->withQueryString());
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

        return response()->json($query->orderBy('employee_number')->paginate(self::PER_PAGE, ['id', 'employee_number', 'full_name', 'name_en', 'gender', 'status', 'current_assignment_id'])->withQueryString());
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

        return response()->json($query->orderByDesc('effective_from')->paginate(self::PER_PAGE, ['id', 'employee_id', 'organization_id', 'position_id', 'assignment_status', 'is_current', 'effective_from', 'effective_to'])->withQueryString());
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

        return response()->json($query->orderByDesc('created_at')->paginate(self::PER_PAGE, ['id', 'employee_id', 'card_number', 'status', 'is_current', 'issued_at', 'expires_at'])->withQueryString());
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

        return response()->json($query->orderByDesc('created_at')->paginate(self::PER_PAGE, ['id', 'actor_user_id', 'action', 'subject_type', 'subject_id', 'organization_id', 'reason', 'request_id', 'created_at'])->withQueryString());
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
