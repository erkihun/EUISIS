<?php

declare(strict_types=1);

namespace Modules\Grievance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Organization;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Grievance\Application\Services\CommitteePermissions;
use Modules\Grievance\Application\Services\GrievanceCommitteeService;
use Modules\Grievance\Application\Services\GrievanceHandlerRegistry;
use Modules\Grievance\Application\Services\GrievanceSettings;
use Modules\Grievance\Contracts\Data\CommitteeType;
use Modules\Grievance\Contracts\Data\GrievanceCommitteeRole;
use Modules\Grievance\Domain\Models\GrievanceCommittee;
use Modules\Grievance\Domain\Models\GrievanceCommitteeMember;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Committees and their membership history. Served twice (ADR 0031): under
 * Grievance Management for every committee type except disciplinary, and
 * under Discipline Management (`discipline.committees.*`) for disciplinary
 * committees only, each with its own module's permissions.
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
        abort_unless($user->can($this->perm($request, 'view')), 403);

        $query = $this->ofDomain($request, $this->scope->applyOrganizationScope(GrievanceCommittee::query(), $user))
            ->with([
                'organizationUnit:id,name_en,name_am',
                'organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am',
                // The serving members are listed under each committee on the list page.
                'members' => fn ($q) => $q->servingOn(now())->with(['employee.currentAssignment.organizationUnit:id,name_en,name_am', 'employee.currentAssignment.position:id,title_en,title_am']),
            ])
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

        // Counts for the summary row: everything the viewer may see, before filters.
        $summary = $this->ofDomain($request, $this->scope->applyOrganizationScope(GrievanceCommittee::query(), $user))
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status = 'active' then 1 else 0 end) as active")
            ->selectRaw("sum(case when status = 'pending_approval' then 1 else 0 end) as pending")
            ->first();
        $servingMembers = GrievanceCommitteeMember::query()->servingOn(now())
            ->whereIn('committee_id', $this->ofDomain($request, $this->scope->applyOrganizationScope(GrievanceCommittee::query(), $user))->select('id'))->count();

        $page = $query->orderBy('name_en')->paginate(12)->withQueryString();
        $page->through(fn (GrievanceCommittee $c) => [
            ...$this->row($c),
            'description_en' => $c->description_en,
            'description_am' => $c->description_am,
            'active_members_count' => $c->active_members_count,
            'open_cases_count' => $c->open_cases_count,
            'is_grievance_type' => in_array($c->committee_type?->value, GrievanceHandlerRegistry::grievanceCommitteeTypes(), true),
            'problems' => in_array($c->committee_type?->value, GrievanceHandlerRegistry::grievanceCommitteeTypes(), true)
                ? array_map(fn ($p) => __($p), $this->handlers->committeeProblems($c)) : [],
            'members' => $c->members->sortBy(fn (GrievanceCommitteeMember $m) => [$m->roleEnum()->value === 'chairperson' ? 0 : ($m->roleEnum()->value === 'writer' ? 1 : 2), $m->effective_from?->timestamp])->map(fn (GrievanceCommitteeMember $m) => [
                'id' => $m->getKey(),
                'employee' => self::employeeRef($m->employee, $m->employee?->photo_path ? route($this->routePrefix($request).'.members.photo', [$c, $m]) : null),
                'role' => $m->roleEnum()->value,
                'selection_source' => $m->selection_source,
                'term_number' => $m->term_number,
                'effective_from' => $m->effective_from?->toDateString(),
                'effective_to' => $m->effective_to?->toDateString(),
                'appointment_reference' => $m->appointment_reference,
                'signature' => $m->hasSignature(),
                'signature_url' => $m->hasSignature() ? route($this->routePrefix($request).'.members.signature', [$c, $m]) : null,
            ])->values(),
            'can_update' => $this->allowed($request, 'manage', $c->organization_id),
            'can_manage_members' => $this->allowed($request, 'members', $c->organization_id),
        ]);

        return Inertia::render('Grievances/Committees/Index', [
            'committees' => $page,
            'filters' => $request->only(['organization_id', 'committee_type', 'status', 'search']),
            ...$this->domainProps($request),
            'options' => [
                'organizations' => $this->organizations($request),
                'types' => $this->isDiscipline($request) ? [CommitteeType::Disciplinary->value]
                    : array_values(array_diff(array_column(CommitteeType::cases(), 'value'), [CommitteeType::Disciplinary->value])),
                'statuses' => ['pending_approval', 'active', 'inactive'],
                'roles' => GrievanceCommitteeRole::values(),
            ],
            'summary' => [
                'total' => (int) ($summary->total ?? 0),
                'active' => (int) ($summary->active ?? 0),
                'pending' => (int) ($summary->pending ?? 0),
                'members' => $servingMembers,
            ],
            'policy' => [
                'min' => $this->settings->committeeMinMembers(),
                'max' => $this->settings->committeeMaxMembers(),
                'require_writer' => $this->settings->committeeRequiresWriter(),
            ],
            'can' => ['create' => $user->can($this->perm($request, 'manage'))],
        ]);
    }

    /** Registration has its own page; the list stays a list. */
    public function create(Request $request): Response
    {
        abort_unless($request->user()->can($this->perm($request, 'manage')), 403);

        return Inertia::render('Grievances/Committees/Create', [
            ...$this->domainProps($request),
            'options' => [
                'organizations' => $this->organizations($request),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Each page registers its own kind: grievance committees, or disciplinary ones (owner decisions 2026-11-07).
        $data = [...$request->validate($this->rules($request)), 'committee_type' => ($this->isDiscipline($request) ? CommitteeType::Disciplinary : CommitteeType::Grievance)->value];
        $committee = $this->committees->create($request->user(), $data);

        return to_route($this->routePrefix($request).'.show', $committee)->with('flash', ['message' => __('grievances.committeeCreated'), 'type' => 'success']);
    }

    public function show(Request $request, GrievanceCommittee $committee): Response
    {
        $user = $request->user();
        $this->guard($request, $committee);
        abort_unless($user->can($this->perm($request, 'view')) && $this->scope->canAccessOrganization($user, $committee->organization_id), 404);

        $committee->load(['organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am', 'members.employee.currentAssignment.organizationUnit:id,name_en,name_am', 'members.employee.currentAssignment.position:id,title_en,title_am', 'members.appointedBy:id,name']);

        return Inertia::render('Grievances/Committees/Show', [
            ...$this->domainProps($request),
            'committee' => [
                ...$this->row($committee),
                'description_en' => $committee->description_en,
                'description_am' => $committee->description_am,
                'approved_at' => $committee->approved_at?->toIso8601String(),
                'created_by' => $committee->created_by,
            ],
            'members' => $committee->members->sortByDesc(fn ($m) => [$m->isActive(), $m->effective_from?->timestamp])->map(fn (GrievanceCommitteeMember $m) => [
                'id' => $m->getKey(),
                'employee' => self::employeeRef($m->employee, $m->employee?->photo_path ? route($this->routePrefix($request).'.members.photo', [$committee, $m]) : null),
                'role' => $m->roleEnum()->value,
                'selection_source' => $m->selection_source,
                'term_number' => $m->term_number,
                'effective_from' => $m->effective_from?->toDateString(),
                'effective_to' => $m->effective_to?->toDateString(),
                'status' => $m->status,
                'is_active' => $m->isActive(),
                'appointment_reference' => $m->appointment_reference,
                'appointed_by' => $m->appointedBy?->name,
                'end_reason' => $m->end_reason,
                'signature' => $m->hasSignature(),
                'signature_url' => $m->hasSignature() ? route($this->routePrefix($request).'.members.signature', [$committee, $m]) : null,
                'signature_recorded_at' => $m->signature_recorded_at?->toIso8601String(),
            ])->values(),
            'problems' => in_array($committee->committee_type?->value, GrievanceHandlerRegistry::grievanceCommitteeTypes(), true)
                ? array_map(fn ($p) => __($p), [
                    ...$this->handlers->committeeProblems($committee),
                    // ADR 0028: approval waits for every serving member's signature.
                    ...($committee->status === 'pending_approval' && $this->committees->membersWithoutSignature($committee)->isNotEmpty() ? ['grievances.errors.committee_signatures_missing'] : []),
                ]) : [],
            'openCases' => $committee->stages()->open()->count(),
            'policy' => [
                'min' => $this->settings->committeeMinMembers(),
                'max' => $this->settings->committeeMaxMembers(),
                'require_writer' => $this->settings->committeeRequiresWriter(),
            ],
            'roles' => GrievanceCommitteeRole::values(),
            'can' => [
                'update' => $this->allowed($request, 'manage', $committee->organization_id),
                'approve' => $committee->status === 'pending_approval' && $user->can($this->perm($request, 'approve')) && (int) $committee->created_by !== (int) $user->getKey(),
                'manage_members' => $this->allowed($request, 'members', $committee->organization_id),
            ],
        ]);
    }

    public function update(Request $request, GrievanceCommittee $committee): RedirectResponse
    {
        $this->guard($request, $committee);
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
        $this->guard($request, $committee);
        $this->committees->approve($committee, $request->user());

        return back()->with('flash', ['message' => __('grievances.flash.committee_approved'), 'type' => 'success']);
    }

    public function addMember(Request $request, GrievanceCommittee $committee): RedirectResponse
    {
        $this->guard($request, $committee);
        $data = $request->validate([
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'role' => ['required', Rule::in(GrievanceCommitteeRole::values())],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'appointment_reference' => ['nullable', 'string', 'max:255'],
            // Regulation 201/2018 Arts. 5, 30: how the member took the seat, and the term number (Art. 7).
            'selection_source' => ['nullable', Rule::in(['appointed', 'elected', 'hr_representative'])],
            'term_number' => ['nullable', 'integer', 'min:1', 'max:2'],
            // ADR 0028: the member's signature image, registered with the appointment.
            'signature' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:1024'],
        ]);
        $this->committees->addMember($committee, $request->user(), [...$data, 'signature' => $request->file('signature')]);

        return back()->with('flash', ['message' => __('grievances.memberAdded'), 'type' => 'success']);
    }

    /** Correct the current term (role, selection, dates, reference, replacement signature); history is kept. */
    public function updateMember(Request $request, GrievanceCommittee $committee, GrievanceCommitteeMember $member): RedirectResponse
    {
        $this->guard($request, $committee);
        $data = $request->validate([
            'role' => ['required', Rule::in(GrievanceCommitteeRole::values())],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'appointment_reference' => ['nullable', 'string', 'max:255'],
            'selection_source' => ['nullable', Rule::in(['appointed', 'elected', 'hr_representative'])],
            'term_number' => ['nullable', 'integer', 'in:1,2'],
            'signature' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:1024'],
        ]);
        $this->committees->updateMember($committee, $member, $request->user(), [...$data, 'signature' => $request->file('signature')]);

        return back()->with('flash', ['message' => __('grievances.memberUpdated'), 'type' => 'success']);
    }

    /** The signature image registered with a member's term; private, for committee viewers only. */
    /**
     * A member's employee photo, for viewers of the committee. Served here (not
     * through the HR employee-photo route) so committee viewers without HR
     * employee rights still see who sits on it; nothing else of the record is exposed.
     */
    public function memberPhoto(Request $request, GrievanceCommittee $committee, GrievanceCommitteeMember $member): StreamedResponse
    {
        $user = $request->user();
        $this->guard($request, $committee);
        abort_unless($user->can($this->perm($request, 'view')) && $this->scope->canAccessOrganization($user, $committee->organization_id), 404);
        abort_unless($member->committee_id === $committee->getKey(), 404);

        return self::photoResponse($member->employee?->photo_path);
    }

    /**
     * The employee shown on a member tile: name, number, current unit and
     * position, and a photo URL that the caller authorizes.
     *
     * @return array<string, mixed>|null
     */
    public static function employeeRef(?Employee $employee, ?string $photoUrl): ?array
    {
        if ($employee === null) {
            return null;
        }
        $assignment = $employee->currentAssignment;

        return [
            'id' => $employee->getKey(),
            'name' => $employee->full_name,
            'name_en' => $employee->name_en ?: $employee->full_name,
            'employee_number' => $employee->employee_number,
            'unit' => $assignment?->organizationUnit?->only(['name_en', 'name_am']),
            'position' => $assignment?->position ? ['name_en' => $assignment->position->title_en, 'name_am' => $assignment->position->title_am] : null,
            'photo_url' => $photoUrl,
        ];
    }

    public static function photoResponse(?string $path): StreamedResponse
    {
        abort_unless($path !== null && preg_match('#^employee-photos/[a-zA-Z0-9-]+\.(png|jpg|jpeg|webp)$#D', $path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function memberSignature(Request $request, GrievanceCommittee $committee, GrievanceCommitteeMember $member): StreamedResponse
    {
        $user = $request->user();
        $this->guard($request, $committee);
        abort_unless($user->can($this->perm($request, 'view')) && $this->scope->canAccessOrganization($user, $committee->organization_id), 404);
        abort_unless($member->committee_id === $committee->getKey(), 404);
        $path = $member->signaturePath();
        abort_unless($path !== null && preg_match('#^employee-signatures/[a-zA-Z0-9-]+\.(png|jpg|jpeg|webp)$#D', $path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function endMember(Request $request, GrievanceCommittee $committee, GrievanceCommitteeMember $member): RedirectResponse
    {
        $this->guard($request, $committee);
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

    /** Discipline Management's committee pages (`discipline.committees.*`), else Grievance Management's. */
    private function isDiscipline(Request $request): bool
    {
        return str_starts_with((string) $request->route()?->getName(), 'discipline.');
    }

    private function routePrefix(Request $request): string
    {
        return $this->isDiscipline($request) ? 'discipline.committees' : 'grievances.committees';
    }

    private function perm(Request $request, string $ability): string
    {
        return CommitteePermissions::for($this->isDiscipline($request) ? CommitteeType::Disciplinary : CommitteeType::Grievance)[$ability];
    }

    private function allowed(Request $request, string $ability, ?string $organizationId): bool
    {
        $permission = $this->perm($request, $ability);

        return $request->user()->can($permission) && $this->scope->canExercisePermission($request->user(), $permission, $organizationId);
    }

    /**
     * @param  Builder<GrievanceCommittee>  $query
     * @return Builder<GrievanceCommittee>
     */
    private function ofDomain(Request $request, $query)
    {
        return $this->isDiscipline($request)
            ? $query->where('committee_type', CommitteeType::Disciplinary->value)
            : $query->where('committee_type', '!=', CommitteeType::Disciplinary->value);
    }

    /** A committee opens only through its own module's pages. */
    private function guard(Request $request, GrievanceCommittee $committee): void
    {
        abort_unless(($committee->committee_type === CommitteeType::Disciplinary) === $this->isDiscipline($request), 404);
    }

    /** @return array{domain: string, route_prefix: string} */
    private function domainProps(Request $request): array
    {
        return ['domain' => $this->isDiscipline($request) ? 'discipline' : 'grievance', 'route_prefix' => $this->routePrefix($request)];
    }

    /** @return array<string, mixed> */
    private function rules(Request $request): array
    {
        return [
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            // Optional: the work unit whose staff this committee serves (e.g. Teachers); a unit of the same institution.
            'organization_unit_id' => ['nullable', 'uuid', Rule::exists('organization_units', 'id')->where('organization_id', (string) $request->input('organization_id'))],
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
