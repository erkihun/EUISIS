<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\CommitteeType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\GrievanceCommittee;
use App\Models\GrievanceCommitteeMember;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GrievanceCommitteeController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', GrievanceCommittee::class);

        $query = GrievanceCommittee::query()
            ->with(['organization', 'organizationUnit'])
            ->withCount('activeMembers');

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->string('organization_id'));
        }

        return Inertia::render('GrievanceCommittees/Index', [
            'committees' => $query->paginate(20)->withQueryString(),
            'organizations' => Organization::query()->where('status', 'active')->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
            'filters' => $request->only('organization_id'),
            'can' => ['create' => \Auth::user()->can('create', GrievanceCommittee::class)],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', GrievanceCommittee::class);

        return Inertia::render('GrievanceCommittees/Create', [
            'organizations' => Organization::query()->where('status', 'active')->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
            'units' => OrganizationUnit::query()->where('status', 'active')->orderBy('name_en')->get(['id', 'name_en', 'name_am', 'organization_id']),
            'committeeTypes' => array_column(CommitteeType::cases(), 'value'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', GrievanceCommittee::class);

        $data = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'],
            'committee_type' => ['required', 'string', 'in:'.implode(',', array_column(CommitteeType::cases(), 'value'))],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
        ]);

        $committee = GrievanceCommittee::query()->create($data);

        return to_route('grievance-committees.show', $committee)
            ->with('flash', ['message' => __('grievances.committeeCreated'), 'type' => 'success']);
    }

    public function show(GrievanceCommittee $grievanceCommittee): Response
    {
        $this->authorize('view', $grievanceCommittee);

        $grievanceCommittee->load(['organization', 'organizationUnit', 'members.employee']);

        $activeMembersCount = $grievanceCommittee->members()
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', now())
            ->where(function ($q): void {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now());
            })
            ->count();

        return Inertia::render('GrievanceCommittees/Show', [
            'committee' => $grievanceCommittee,
            'activeMembersCount' => $activeMembersCount,
            'availableEmployees' => Employee::query()
                ->where('status', 'active')
                ->whereHas('currentAssignment', function ($q) use ($grievanceCommittee): void {
                    $q->where('organization_id', $grievanceCommittee->organization_id);
                })
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'employee_number']),
            'can' => [
                'update' => \Auth::user()->can('update', $grievanceCommittee),
                'addMember' => \Auth::user()->can('update', $grievanceCommittee),
            ],
        ]);
    }

    public function addMember(Request $request, GrievanceCommittee $grievanceCommittee): RedirectResponse
    {
        $this->authorize('update', $grievanceCommittee);

        $data = $request->validate([
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'role' => ['required', 'string', 'in:chairperson,secretary,member'],
            'effective_from' => ['required', 'date'],
        ]);

        $activeMembersCount = $grievanceCommittee->members()
            ->where('status', 'active')
            ->whereNull('effective_to')
            ->count();

        if ($activeMembersCount >= 5) {
            throw ValidationException::withMessages([
                'employee_id' => __('grievances.committeeMaxMembers'),
            ]);
        }

        if ($data['role'] === 'chairperson') {
            $alreadyHasChairperson = $grievanceCommittee->members()
                ->where('role', 'chairperson')
                ->where('status', 'active')
                ->whereNull('effective_to')
                ->exists();

            if ($alreadyHasChairperson) {
                throw ValidationException::withMessages([
                    'role' => __('grievances.committeeAlreadyHasChairperson'),
                ]);
            }
        }

        GrievanceCommitteeMember::query()->create([
            'committee_id' => $grievanceCommittee->id,
            'employee_id' => $data['employee_id'],
            'role' => $data['role'],
            'effective_from' => $data['effective_from'],
            'status' => 'active',
        ]);

        return back()->with('flash', ['message' => __('grievances.memberAdded'), 'type' => 'success']);
    }

    public function removeMember(GrievanceCommittee $grievanceCommittee, GrievanceCommitteeMember $member): RedirectResponse
    {
        $this->authorize('update', $grievanceCommittee);

        $activeMembersCount = $grievanceCommittee->members()
            ->where('status', 'active')
            ->whereNull('effective_to')
            ->count();

        if ($activeMembersCount <= 3) {
            throw ValidationException::withMessages([
                'member' => __('grievances.committeeMinMembers'),
            ]);
        }

        $member->update(['status' => 'inactive', 'effective_to' => now()->toDateString()]);

        return back()->with('flash', ['message' => __('grievances.memberRemoved'), 'type' => 'success']);
    }

    public function destroy(GrievanceCommittee $grievanceCommittee): RedirectResponse
    {
        $this->authorize('delete', $grievanceCommittee);

        $grievanceCommittee->delete();

        return to_route('grievance-committees.index')
            ->with('flash', ['message' => __('grievances.committeeDeleted'), 'type' => 'success']);
    }
}
