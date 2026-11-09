<?php

declare(strict_types=1);

namespace App\Http\Controllers\Core;

use App\Enums\AssignmentStatus;
use App\Http\Controllers\Controller;
use App\Models\EmployeeAssignment;
use App\Services\OrganizationScope\OrganizationScopeService;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /core/assignments: who holds which placement, inside the viewer's
 * organization scope. Read-only: placements change through the transfer
 * workflow and the employment status (ADR 0002), never from this list.
 */
class CoreAssignmentController extends Controller
{
    private const PER_PAGE = 25;

    public function __invoke(Request $request, OrganizationScopeService $scope): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'organization' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::enum(AssignmentStatus::class)],
            'history' => ['nullable', 'boolean'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $organizationCode = trim((string) ($filters['organization'] ?? ''));
        $withHistory = (bool) ($filters['history'] ?? false);

        $query = EmployeeAssignment::query()
            ->select(['id', 'employee_id', 'organization_id', 'organization_unit_id', 'position_id', 'assignment_status', 'is_current', 'effective_from', 'effective_to'])
            ->with([
                'employee:id,employee_number,full_name,status',
                'organization:id,code,name_en,name_am',
                'organizationUnit:id,code,name_en,name_am',
                'position:id,job_position_code,title_en,title_am',
            ])
            ->when(! $withHistory, fn ($q) => $q->where('is_current', true))
            ->when(isset($filters['status']), fn ($q) => $q->where('assignment_status', $filters['status']))
            ->when($organizationCode !== '', fn ($q) => $q->whereHas('organization', fn ($o) => $o->where('code', $organizationCode)))
            ->when($search !== '', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('employee_number', $search)
                ->orWhere('full_name', ci_like_operator(), '%'.$search.'%')))
            ->orderByDesc('effective_from')
            ->orderBy('id');

        // The one canonical scope rule: a placement is visible where its organization is.
        $scope->applyOrganizationScope($query, $request->user());

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Core/Assignments', [
            'assignments' => $page->getCollection()->map(fn (EmployeeAssignment $assignment): array => [
                'id' => $assignment->id,
                'status' => $assignment->assignment_status->value,
                'is_current' => (bool) $assignment->is_current,
                'effective_from' => $assignment->effective_from?->toDateString(),
                'effective_to' => $assignment->effective_to?->toDateString(),
                'employee' => $assignment->employee === null ? null : [
                    'id' => $assignment->employee->id,
                    'employee_number' => $assignment->employee->employee_number,
                    'full_name' => $assignment->employee->full_name,
                    'status' => $assignment->employee->status instanceof BackedEnum ? $assignment->employee->status->value : $assignment->employee->status,
                ],
                'organization' => $assignment->organization?->only(['id', 'code', 'name_en', 'name_am']),
                'unit' => $assignment->organizationUnit?->only(['id', 'code', 'name_en', 'name_am']),
                'position' => $assignment->position === null ? null : [
                    'id' => $assignment->position->id,
                    'code' => $assignment->position->job_position_code,
                    'title_en' => $assignment->position->title_en,
                    'title_am' => $assignment->position->title_am,
                ],
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'filters' => [
                'search' => $search,
                'organization' => $organizationCode,
                'status' => $filters['status'] ?? '',
                'history' => $withHistory,
            ],
            'statuses' => array_map(fn (AssignmentStatus $status): string => $status->value, AssignmentStatus::cases()),
        ]);
    }
}
