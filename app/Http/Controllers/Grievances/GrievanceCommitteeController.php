<?php

declare(strict_types=1);

namespace App\Http\Controllers\Grievances;

use App\Enums\CommitteeType;
use App\Enums\Grievance\GrievanceCommitteeRole;
use App\Http\Controllers\Controller;
use App\Models\GrievanceCommittee;
use App\Models\GrievanceCommitteeMember;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Services\Grievances\GrievanceCommitteeService;
use App\Services\Grievances\GrievanceHandlerRegistry;
use App\Services\Grievances\GrievanceSettings;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Grievance committees and their membership history. Also lists the EPMS
 * panels that reuse the same model (filtered by type) so there is one place
 * to see who sits where.
 */
class GrievanceCommitteeController extends Controller
{
    public function __construct(
        private readonly GrievanceCommitteeService $committees,
        private readonly GrievanceHandlerRegistry $handlers,
        private readonly GrievanceSettings $settings,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('grievance_committees.view'), 403);

        $query = $this->scope->applyOrganizationScope(GrievanceCommittee::query(), $user)
            ->with(['organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am'])
            ->withCount(['members as active_members_count' => fn ($q) => $q->servingOn(now())])
            ->withCount(['stages as open_cases_count' => fn ($q) => $q->open()]);

        foreach (['organization_id', 'committee_type', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (string) $request->string($filter));
            }
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->string('search'));
            $query->where(fn ($q) => $q->where('name_en', 'like', "%{$term}%")->orWhere('name_am', 'like', "%{$term}%"));
        }

        $page = $query->orderBy('name_en')->paginate(20)->withQueryString();
        $page->through(fn (GrievanceCommittee $c) => [
            ...$this->row($c),
            'active_members_count' => $c->active_members_count,
            'open_cases_count' => $c->open_cases_count,
            'problems' => in_array($c->committee_type?->value, GrievanceHandlerRegistry::grievanceCommitteeTypes(), true)
                ? array_map(fn ($p) => __($p), $this->handlers->committeeProblems($c)) : [],
        ]);

        return Inertia::render('Grievances/Committees/Index', [
            'committees' => $page,
            'filters' => $request->only(['organization_id', 'committee_type', 'status', 'search']),
            'options' => [
                'organizations' => $this->organizations($request),
                'types' => array_column(CommitteeType::cases(), 'value'),
                'statuses' => ['pending_approval', 'active', 'inactive'],
            ],
            'can' => ['create' => $user->can('grievance_committees.manage')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $committee = $this->committees->create($request->user(), $data);

        return to_route('grievances.committees.show', $committee)->with('flash', ['message' => __('grievances.committeeCreated'), 'type' => 'success']);
    }

    public function show(Request $request, GrievanceCommittee $committee): Response
    {
        $user = $request->user();
        abort_unless($user->can('grievance_committees.view') && $this->scope->canAccessOrganization($user, $committee->organization_id), 404);

        $committee->load(['organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am', 'members.employee', 'members.appointedBy:id,name']);

        return Inertia::render('Grievances/Committees/Show', [
            'committee' => [
                ...$this->row($committee),
                'description_en' => $committee->description_en,
                'description_am' => $committee->description_am,
                'approved_at' => $committee->approved_at?->toIso8601String(),
                'created_by' => $committee->created_by,
            ],
            'members' => $committee->members->sortByDesc(fn ($m) => [$m->isActive(), $m->effective_from?->timestamp])->map(fn (GrievanceCommitteeMember $m) => [
                'id' => $m->getKey(),
                'employee' => $m->employee ? ['id' => $m->employee->getKey(), 'name' => $m->employee->full_name, 'name_en' => $m->employee->name_en ?: $m->employee->full_name, 'employee_number' => $m->employee->employee_number] : null,
                'role' => $m->roleEnum()->value,
                'effective_from' => $m->effective_from?->toDateString(),
                'effective_to' => $m->effective_to?->toDateString(),
                'status' => $m->status,
                'is_active' => $m->isActive(),
                'appointment_reference' => $m->appointment_reference,
                'appointed_by' => $m->appointedBy?->name,
                'end_reason' => $m->end_reason,
            ])->values(),
            'problems' => in_array($committee->committee_type?->value, GrievanceHandlerRegistry::grievanceCommitteeTypes(), true)
                ? array_map(fn ($p) => __($p), $this->handlers->committeeProblems($committee)) : [],
            'openCases' => $committee->stages()->open()->count(),
            'policy' => [
                'min' => $this->settings->committeeMinMembers(),
                'max' => $this->settings->committeeMaxMembers(),
                'require_writer' => $this->settings->committeeRequiresWriter(),
            ],
            'roles' => GrievanceCommitteeRole::values(),
            'units' => OrganizationUnit::query()->where('organization_id', $committee->organization_id)->where('status', 'active')->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
            'can' => [
                'update' => $user->can('grievance_committees.manage') && $this->scope->canExercisePermission($user, 'grievance_committees.manage', $committee->organization_id),
                'approve' => $committee->status === 'pending_approval' && $user->can('grievance_committees.approve') && (int) $committee->created_by !== (int) $user->getKey(),
                'manage_members' => $user->can('grievance_committee_members.manage') && $this->scope->canExercisePermission($user, 'grievance_committee_members.manage', $committee->organization_id),
            ],
        ]);
    }

    public function update(Request $request, GrievanceCommittee $committee): RedirectResponse
    {
        $data = $request->validate([
            'name_en' => ['sometimes', 'required', 'string', 'max:255'],
            'name_am' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'description_am' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'organization_unit_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('organization_units', 'id')->where('organization_id', $committee->organization_id)],
            'effective_to' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'in:inactive'],
        ]);
        $this->committees->update($committee, $request->user(), $data);

        return back()->with('flash', ['message' => __('grievances.flash.saved'), 'type' => 'success']);
    }

    public function approve(Request $request, GrievanceCommittee $committee): RedirectResponse
    {
        $this->committees->approve($committee, $request->user());

        return back()->with('flash', ['message' => __('grievances.flash.committee_approved'), 'type' => 'success']);
    }

    public function addMember(Request $request, GrievanceCommittee $committee): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'role' => ['required', Rule::in(GrievanceCommitteeRole::values())],
            'effective_from' => ['required', 'date'],
            'appointment_reference' => ['nullable', 'string', 'max:255'],
        ]);
        $this->committees->addMember($committee, $request->user(), $data);

        return back()->with('flash', ['message' => __('grievances.memberAdded'), 'type' => 'success']);
    }

    public function endMember(Request $request, GrievanceCommittee $committee, GrievanceCommitteeMember $member): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'effective_to' => ['nullable', 'date']]);
        $this->committees->endMember($committee, $member, $request->user(), $data['reason'], isset($data['effective_to']) ? Carbon::parse($data['effective_to']) : null);

        return back()->with('flash', ['message' => __('grievances.memberRemoved'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function row(GrievanceCommittee $c): array
    {
        return [
            'id' => $c->getKey(),
            'name_en' => $c->name_en,
            'name_am' => $c->name_am,
            'committee_type' => $c->committee_type?->value,
            'status' => $c->status,
            'organization' => $c->organization?->only(['id', 'name_en', 'name_am']),
            'organization_unit' => $c->organizationUnit?->only(['id', 'name_en', 'name_am']),
            'effective_from' => $c->effective_from?->toDateString(),
            'effective_to' => $c->effective_to?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'],
            'committee_type' => ['required', Rule::in(array_column(CommitteeType::cases(), 'value'))],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_am' => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }

    /** @return mixed */
    private function organizations(Request $request)
    {
        return $this->scope->applyOrganizationScope(Organization::query(), $request->user(), 'id')->where('status', 'active')->orderBy('name_en')->limit(1000)->get(['id', 'name_en', 'name_am']);
    }
}
