<?php

declare(strict_types=1);

namespace Modules\Feedback\Http\Controllers;

use App\Backbone\Contracts\EmployeeDirectory;
use App\Backbone\Contracts\OrganizationDirectory;
use App\Backbone\Contracts\UnitDirectory;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Feedback\Application\Services\PublicCaseHandlingUnitService;
use Modules\Feedback\Application\Services\PublicFeedbackRoutingService;
use Modules\Feedback\Domain\Models\PublicCaseHandlingUnit;
use Modules\Feedback\Domain\Models\PublicCaseHandlingUnitMember;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public Tip-off and complaint handling units (ADR 0034): pick an
 * institution's work unit, name the staff who serve in it, and register each
 * one's signature.
 */
class PublicCaseHandlingUnitController extends Controller
{
    private const SIGNATURE_RULES = ['file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:1024'];

    public function __construct(
        private readonly PublicCaseHandlingUnitService $handling,
        private readonly OrganizationScopeService $scope,
        private readonly UnitDirectory $units,
        private readonly EmployeeDirectory $employees,
        private readonly OrganizationDirectory $organizations,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        abort_unless($user->hasAnyPermission([PublicFeedbackRoutingService::PERMISSION]), 403);
        $locale = app()->getLocale();

        $query = PublicCaseHandlingUnit::query()->with(['members' => fn ($q) => $q->servingOn(now())->orderByRaw("CASE WHEN responsibility = 'head' THEN 0 ELSE 1 END")->orderBy('effective_from')]);
        if (! $this->scope->isUnrestricted($user)) {
            $query->whereIn('organization_id', $this->scope->accessibleOrganizationIds($user)->all());
        }
        if ($request->filled('organization_id')) {
            $query->where('organization_id', (string) $request->string('organization_id'));
        }
        $caseType = in_array($request->query('case_type'), PublicCaseHandlingUnit::CASE_TYPES, true) ? (string) $request->query('case_type') : '';
        if ($caseType !== '') {
            $query->where('case_type', $caseType);
        }

        $rows = $query->orderBy('organization_id')->orderBy('case_type')->orderBy('created_at')->get();
        $orgNames = $this->organizationNames($rows->pluck('organization_id')->unique()->all(), $locale);

        return Inertia::render('ServiceFeedback/HandlingUnits/Index', [
            'units' => $rows->map(function (PublicCaseHandlingUnit $d) use ($user, $locale, $orgNames): array {
                $unit = $this->units->find((string) $d->organization_unit_id);

                return [
                    'id' => $d->getKey(),
                    'case_type' => $d->case_type,
                    'status' => $d->status,
                    'description' => $locale === 'am' ? ($d->description_am ?: $d->description_en) : ($d->description_en ?: $d->description_am),
                    'organization_id' => $d->organization_id,
                    'organization' => $orgNames[$d->organization_id] ?? null,
                    'unit' => $unit === null ? null : ($locale === 'am' ? ($unit->nameAm ?: $unit->nameEn) : ($unit->nameEn ?: $unit->nameAm)),
                    'problems' => array_map(fn (string $key): string => __($key), $this->handling->problems($d)),
                    'members' => $d->members->map(fn (PublicCaseHandlingUnitMember $m): array => $this->memberRow($d, $m))->values()->all(),
                    'can_manage' => $this->handling->canManage($user, (string) $d->organization_id),
                ];
            })->values()->all(),
            'organizations' => $this->organizationOptions($user, $locale),
            'filters' => ['organization_id' => (string) $request->query('organization_id', ''), 'case_type' => $caseType],
            'caseTypes' => PublicCaseHandlingUnit::CASE_TYPES,
        ]);
    }

    /** The designation form, on its own page. */
    public function create(Request $request): Response
    {
        $user = $this->user($request);
        abort_unless($user->hasAnyPermission([PublicFeedbackRoutingService::PERMISSION]), 403);
        $organizations = $this->organizationOptions($user, app()->getLocale());
        abort_if($organizations === [], 403);
        $caseType = in_array($request->query('case_type'), PublicCaseHandlingUnit::CASE_TYPES, true) ? (string) $request->query('case_type') : 'suggestion';

        return Inertia::render('ServiceFeedback/HandlingUnits/Create', ['organizations' => $organizations, 'caseTypes' => PublicCaseHandlingUnit::CASE_TYPES, 'caseType' => $caseType]);
    }

    public function createMember(Request $request, PublicCaseHandlingUnit $unit): Response
    {
        abort_unless($this->handling->canManage($this->user($request), (string) $unit->organization_id) && $unit->isActive(), 403);

        return Inertia::render('ServiceFeedback/HandlingUnits/MemberCreate', ['unit' => $this->unitRef($unit), 'responsibilities' => PublicCaseHandlingUnitMember::RESPONSIBILITIES]);
    }

    public function editMember(Request $request, PublicCaseHandlingUnit $unit, PublicCaseHandlingUnitMember $member): Response
    {
        abort_unless($member->handling_unit_id === $unit->getKey(), 404);
        abort_unless($this->handling->canManage($this->user($request), (string) $unit->organization_id), 403);

        return Inertia::render('ServiceFeedback/HandlingUnits/MemberEdit', ['unit' => $this->unitRef($unit), 'member' => $this->memberRow($unit, $member), 'responsibilities' => PublicCaseHandlingUnitMember::RESPONSIBILITIES]);
    }

    /** An institution's active work units not yet designated for the case type, for the designation form. */
    public function unitOptions(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $organizationId = (string) $request->query('organization_id', '');
        abort_unless($organizationId !== '' && $this->handling->canManage($user, $organizationId), 403);
        $taken = PublicCaseHandlingUnit::query()->where('organization_id', $organizationId)
            ->when(in_array($request->query('case_type'), PublicCaseHandlingUnit::CASE_TYPES, true), fn ($q) => $q->where('case_type', (string) $request->query('case_type')))
            ->pluck('organization_unit_id')->map(fn ($id) => (string) $id)->all();
        $locale = app()->getLocale();

        return response()->json(['data' => collect($this->units->forOrganization($organizationId))
            ->reject(fn ($unit) => in_array($unit->unitUuid, $taken, true))
            ->map(fn ($unit) => ['id' => $unit->unitUuid, 'name' => $locale === 'am' ? ($unit->nameAm ?: $unit->nameEn) : ($unit->nameEn ?: $unit->nameAm)])
            ->sortBy('name')->values()->all()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'organization_unit_id' => ['required', 'uuid', Rule::exists('organization_units', 'id')->where('organization_id', (string) $request->input('organization_id'))],
            'case_type' => ['required', Rule::in(PublicCaseHandlingUnit::CASE_TYPES)],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_am' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->handling->designate($this->user($request), $data);

        return redirect()->route('service-feedback.handling-units.index', ['case_type' => $data['case_type']])->with('success', __('service-feedback.handling_unit_saved'));
    }

    public function update(Request $request, PublicCaseHandlingUnit $unit): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_am' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->handling->update($unit, $this->user($request), $data);

        return back()->with('success', __('service-feedback.handling_unit_saved'));
    }

    public function addMember(Request $request, PublicCaseHandlingUnit $unit): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'responsibility' => ['required', Rule::in(PublicCaseHandlingUnitMember::RESPONSIBILITIES)],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'appointment_reference' => ['nullable', 'string', 'max:255'],
            'signature' => ['required', ...self::SIGNATURE_RULES],
        ], ['signature.required' => __('service-feedback.handling_signature_required')]);
        $this->handling->addMember($unit, $this->user($request), [...$data, 'signature' => $request->file('signature')]);

        return redirect()->route('service-feedback.handling-units.index', ['case_type' => $unit->case_type])->with('success', __('service-feedback.handling_member_saved'));
    }

    public function updateMember(Request $request, PublicCaseHandlingUnit $unit, PublicCaseHandlingUnitMember $member): RedirectResponse
    {
        $data = $request->validate([
            'responsibility' => ['required', Rule::in(PublicCaseHandlingUnitMember::RESPONSIBILITIES)],
            'appointment_reference' => ['nullable', 'string', 'max:255'],
            'signature' => ['nullable', ...self::SIGNATURE_RULES],
        ]);
        $this->handling->updateMember($unit, $member, $this->user($request), [...$data, 'signature' => $request->file('signature')]);

        return redirect()->route('service-feedback.handling-units.index', ['case_type' => $unit->case_type])->with('success', __('service-feedback.handling_member_saved'));
    }

    public function endMember(Request $request, PublicCaseHandlingUnit $unit, PublicCaseHandlingUnitMember $member): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->handling->endMember($unit, $member, $this->user($request), $data['reason']);

        return back()->with('success', __('service-feedback.handling_member_ended_saved'));
    }

    /** The signature registered with a member's term; private, for the unit's administrators. */
    public function signature(Request $request, PublicCaseHandlingUnit $unit, PublicCaseHandlingUnitMember $member): StreamedResponse
    {
        $user = $this->user($request);
        abort_unless($member->handling_unit_id === $unit->getKey() && $user->hasAnyPermission([PublicFeedbackRoutingService::PERMISSION])
            && ($this->scope->isUnrestricted($user) || $this->scope->canAccessOrganization($user, (string) $unit->organization_id)), 404);
        $path = (string) $member->signature_path;
        abort_unless(preg_match('#^employee-signatures/[a-zA-Z0-9-]+\.(png|jpg|jpeg|webp)$#D', $path) === 1 && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array<string, mixed> */
    private function unitRef(PublicCaseHandlingUnit $unit): array
    {
        $locale = app()->getLocale();
        $ou = $this->units->find((string) $unit->organization_unit_id);
        $org = $this->organizations->find((string) $unit->organization_id);

        return [
            'id' => $unit->getKey(),
            'case_type' => $unit->case_type,
            'unit' => $ou === null ? null : ($locale === 'am' ? ($ou->nameAm ?: $ou->nameEn) : ($ou->nameEn ?: $ou->nameAm)),
            'organization' => $org === null ? null : ($locale === 'am' ? ($org->nameAm ?: $org->nameEn) : ($org->nameEn ?: $org->nameAm)),
            'has_head' => $unit->members()->servingOn(now())->where('responsibility', 'head')->exists(),
        ];
    }

    /** @return array<string, mixed> */
    private function memberRow(PublicCaseHandlingUnit $d, PublicCaseHandlingUnitMember $m): array
    {
        $employee = $this->employees->findByUuid((string) $m->employee_id);

        return [
            'id' => $m->getKey(),
            'employee_id' => $m->employee_id,
            'name' => $employee?->fullName ?? $employee?->nameEn,
            'employee_number' => $employee?->employeeNumber,
            'responsibility' => $m->responsibility,
            'effective_from' => $m->effective_from?->toDateString(),
            'effective_to' => $m->effective_to?->toDateString(),
            'appointment_reference' => $m->appointment_reference,
            'placed' => $this->handling->isPlacedIn((string) $m->employee_id, $d),
            'has_account' => User::query()->where('employee_id', $m->employee_id)->where('status', 'active')->exists(),
            'signature_url' => route('service-feedback.handling-units.members.signature', [$d, $m]),
            'signature_recorded_at' => $m->signature_recorded_at?->toIso8601String(),
        ];
    }

    /** @return list<array{id: string, name: string}> */
    private function organizationOptions(User $user, string $locale): array
    {
        $rows = [];
        foreach ($this->organizations->activeFor($user, 1000) as $org) {
            $rows[] = ['id' => $org->organizationUuid, 'name' => (string) ($locale === 'am' ? ($org->nameAm ?: $org->nameEn) : ($org->nameEn ?: $org->nameAm))];
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }

    /** @return array<string, string|null> */
    private function organizationNames(array $ids, string $locale): array
    {
        $names = [];
        foreach ($ids as $id) {
            $org = $this->organizations->find((string) $id);
            $names[(string) $id] = $org === null ? null : ($locale === 'am' ? ($org->nameAm ?: $org->nameEn) : ($org->nameEn ?: $org->nameAm));
        }

        return $names;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
