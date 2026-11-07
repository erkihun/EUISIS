<?php

declare(strict_types=1);

namespace Modules\Grievance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Grievance\Application\Services\GrievanceHandlerUnitService;
use Modules\Grievance\Domain\Enums\GrievanceUnitResponsibility;
use Modules\Grievance\Domain\Models\GrievanceHandlerUnit;
use Modules\Grievance\Domain\Models\GrievanceHandlerUnitMember;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Grievance Teams and Directorates (ADR 0029): designate existing units and
 * name their members with a responsibility and a signature.
 */
class GrievanceHandlerUnitController extends Controller
{
    private const RESPONSIBILITY_ORDER = ['head' => 0, 'secretary' => 1, 'investigator' => 2];

    public function __construct(
        private readonly GrievanceHandlerUnitService $units,
        private readonly OrganizationScopeService $scope,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('grievance_committees.view'), 403);

        $base = fn () => $this->scope->applyOrganizationScope(GrievanceHandlerUnit::query(), $user);
        $query = $base()->with([
            'organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am',
            'members' => fn ($q) => $q->servingOn(now())->with(['employee.currentAssignment.organizationUnit:id,name_en,name_am', 'employee.currentAssignment.position:id,title_en,title_am']),
        ]);
        foreach (['organization_id', 'kind', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (string) $request->string($filter));
            }
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->string('search'));
            $query->whereHas('organizationUnit', fn ($u) => $u->where('name_en', 'like', "%{$term}%")->orWhere('name_am', 'like', "%{$term}%"));
        }

        $page = $query->orderBy('kind')->orderBy('created_at')->paginate(12)->withQueryString();
        $page->through(fn (GrievanceHandlerUnit $d) => [
            'id' => $d->getKey(),
            'kind' => $d->kind,
            'status' => $d->status,
            'description_en' => $d->description_en,
            'description_am' => $d->description_am,
            'organization' => $d->organization?->only(['id', 'name_en', 'name_am']),
            'unit' => $d->organizationUnit?->only(['id', 'name_en', 'name_am']),
            'problems' => array_map(fn ($p) => __($p), $this->units->problems($d)),
            'members' => $d->members
                ->sortBy(fn (GrievanceHandlerUnitMember $m) => [self::RESPONSIBILITY_ORDER[$m->responsibility] ?? 9, $m->effective_from?->timestamp])
                ->map(fn (GrievanceHandlerUnitMember $m) => [
                    'id' => $m->getKey(),
                    'employee' => GrievanceCommitteeController::employeeRef($m->employee, $m->employee?->photo_path ? route('grievances.units.members.photo', [$d, $m]) : null),
                    'responsibility' => $m->responsibility,
                    'effective_from' => $m->effective_from?->toDateString(),
                    'effective_to' => $m->effective_to?->toDateString(),
                    'appointment_reference' => $m->appointment_reference,
                    'signature' => $m->hasSignature(),
                    'signature_url' => $m->hasSignature() ? route('grievances.units.members.signature', [$d, $m]) : null,
                ])->values(),
            'can_update' => $user->can('grievance_committees.manage') && $this->scope->canExercisePermission($user, 'grievance_committees.manage', $d->organization_id),
            'can_manage_members' => $user->can('grievance_committee_members.manage') && $this->scope->canExercisePermission($user, 'grievance_committee_members.manage', $d->organization_id),
        ]);

        $designations = $base()->pluck('id');

        return Inertia::render('Grievances/Units/Index', [
            'units' => $page,
            'filters' => $request->only(['organization_id', 'kind', 'status', 'search']),
            'options' => [
                'organizations' => $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')->where('status', 'active')->orderBy('name_en')->limit(1000)->get(['id', 'name_en', 'name_am']),
                'kinds' => GrievanceHandlerUnit::KINDS,
                'responsibilities' => GrievanceUnitResponsibility::values(),
            ],
            'summary' => [
                'teams' => $base()->where('kind', 'team')->count(),
                'directorates' => $base()->where('kind', 'directorate')->count(),
                'members' => GrievanceHandlerUnitMember::query()->servingOn(now())->whereIn('handler_unit_id', $designations)->count(),
                'unsigned' => GrievanceHandlerUnitMember::query()->servingOn(now())->whereIn('handler_unit_id', $designations)->with('employee:id,signature_path')->get()
                    ->reject(fn (GrievanceHandlerUnitMember $m) => $m->hasSignature())->count(),
            ],
            'can' => ['designate' => $user->can('grievance_committees.manage')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'organization_unit_id' => ['required', 'uuid', Rule::exists('organization_units', 'id')->where('organization_id', (string) $request->input('organization_id'))],
            'kind' => ['required', Rule::in(GrievanceHandlerUnit::KINDS)],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_am' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->units->designate($request->user(), $data);

        return back()->with('flash', ['message' => __('grievances.unitDesignated'), 'type' => 'success']);
    }

    public function update(Request $request, GrievanceHandlerUnit $unit): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['sometimes', Rule::in(GrievanceHandlerUnit::KINDS)],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_am' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->units->update($unit, $request->user(), $data);

        return back()->with('flash', ['message' => __('grievances.unitUpdated'), 'type' => 'success']);
    }

    public function addMember(Request $request, GrievanceHandlerUnit $unit): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'responsibility' => ['required', Rule::in(GrievanceUnitResponsibility::values())],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'appointment_reference' => ['nullable', 'string', 'max:255'],
            'signature' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:1024'],
        ], ['signature.required' => __('grievances.errors.committee_member_signature_required')]);
        $this->units->addMember($unit, $request->user(), [...$data, 'signature' => $request->file('signature')]);

        return back()->with('flash', ['message' => __('grievances.memberAdded'), 'type' => 'success']);
    }

    public function updateMember(Request $request, GrievanceHandlerUnit $unit, GrievanceHandlerUnitMember $member): RedirectResponse
    {
        $data = $request->validate([
            'responsibility' => ['required', Rule::in(GrievanceUnitResponsibility::values())],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'appointment_reference' => ['nullable', 'string', 'max:255'],
            'signature' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:1024'],
        ]);
        $this->units->updateMember($unit, $member, $request->user(), [...$data, 'signature' => $request->file('signature')]);

        return back()->with('flash', ['message' => __('grievances.memberUpdated'), 'type' => 'success']);
    }

    public function endMember(Request $request, GrievanceHandlerUnit $unit, GrievanceHandlerUnitMember $member): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'effective_to' => ['nullable', 'date']]);
        $this->units->endMember($unit, $member, $request->user(), $data['reason'], $data['effective_to'] ?? null);

        return back()->with('flash', ['message' => __('grievances.memberRemoved'), 'type' => 'success']);
    }

    /** A member's employee photo, for viewers of the unit (see the committee equivalent). */
    public function memberPhoto(Request $request, GrievanceHandlerUnit $unit, GrievanceHandlerUnitMember $member): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->can('grievance_committees.view') && $this->scope->canAccessOrganization($user, $unit->organization_id), 404);
        abort_unless($member->handler_unit_id === $unit->getKey(), 404);

        return GrievanceCommitteeController::photoResponse($member->employee?->photo_path);
    }

    /** The signature registered with a member's term; private, for viewers of the unit. */
    public function memberSignature(Request $request, GrievanceHandlerUnit $unit, GrievanceHandlerUnitMember $member): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->can('grievance_committees.view') && $this->scope->canAccessOrganization($user, $unit->organization_id), 404);
        abort_unless($member->handler_unit_id === $unit->getKey(), 404);
        $path = $member->signaturePath();
        abort_unless($path !== null && preg_match('#^employee-signatures/[a-zA-Z0-9-]+\.(png|jpg|jpeg|webp)$#D', $path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
