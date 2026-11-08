<?php

declare(strict_types=1);

namespace Modules\Feedback\Http\Controllers;

use App\Backbone\Contracts\AssignmentContextReader;
use App\Backbone\Contracts\EmployeeDirectory;
use App\Backbone\Contracts\OrganizationDirectory;
use App\Backbone\Contracts\PositionDirectory;
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
 * Each module's unit board (ADR 0034) — Feedback, Tip-off and Complaint Unit:
 * pick an institution's work unit, give the staff who serve in it a
 * responsibility and register each one's signature. Same board as the
 * grievance Teams and Directorate: one card per unit, actions in popups.
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
        private readonly AssignmentContextReader $assignments,
        private readonly PositionDirectory $positions,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        abort_unless($user->hasAnyPermission([PublicFeedbackRoutingService::PERMISSION]), 403);
        $locale = app()->getLocale();
        // Each module's own page (route default): Feedback, Tip-off or Complaint Unit.
        $caseType = $this->routeType($request);

        $base = function () use ($user, $caseType) {
            $query = PublicCaseHandlingUnit::query()->where('case_type', $caseType);
            if (! $this->scope->isUnrestricted($user)) {
                $query->whereIn('organization_id', $this->scope->accessibleOrganizationIds($user)->all());
            }

            return $query;
        };
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'organization_id' => (string) $request->query('organization_id', ''),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? (string) $request->query('status') : '',
        ];

        $query = $base()->with(['members' => fn ($q) => $q->servingOn(now())->orderByRaw("CASE WHEN responsibility = 'head' THEN 0 ELSE 1 END")->orderBy('effective_from')])
            ->when($filters['organization_id'] !== '', fn ($q) => $q->where('organization_id', $filters['organization_id']))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']));
        $rows = $query->orderBy('organization_id')->orderBy('created_at')->get();
        $orgNames = $this->organizationNames($rows->pluck('organization_id')->unique()->all(), $locale);

        $units = $rows->map(function (PublicCaseHandlingUnit $d) use ($user, $locale, $orgNames): array {
            $unit = $this->units->find((string) $d->organization_unit_id);

            return [
                'id' => $d->getKey(),
                'case_type' => $d->case_type,
                'status' => $d->status,
                'description_en' => $d->description_en,
                'description_am' => $d->description_am,
                'organization_id' => $d->organization_id,
                'organization' => $orgNames[$d->organization_id] ?? null,
                'unit' => $unit === null ? null : $this->name($unit->nameEn, $unit->nameAm, $locale),
                'problems' => array_map(fn (string $key): string => __($key), $this->handling->problems($d)),
                'members' => $d->members->map(fn (PublicCaseHandlingUnitMember $m): array => $this->memberRow($d, $m, $locale))->values()->all(),
                'can_manage' => $this->handling->canManage($user, (string) $d->organization_id),
            ];
        });
        if ($filters['search'] !== '') {
            $needle = mb_strtolower($filters['search']);
            $units = $units->filter(fn (array $row): bool => str_contains(mb_strtolower((string) $row['unit']), $needle) || str_contains(mb_strtolower((string) $row['organization']), $needle));
        }

        $designations = $base()->pluck('id');

        return Inertia::render('ServiceFeedback/HandlingUnits/Index', [
            'units' => $units->values()->all(),
            'organizations' => $this->organizationOptions($user, $locale),
            'filters' => $filters,
            'caseType' => $caseType,
            'responsibilities' => PublicCaseHandlingUnitMember::RESPONSIBILITIES,
            'summary' => [
                'units' => $base()->where('status', 'active')->count(),
                'members' => PublicCaseHandlingUnitMember::query()->servingOn(now())->whereIn('handling_unit_id', $designations)->count(),
                'attention' => $base()->get()->filter(fn (PublicCaseHandlingUnit $d): bool => $d->isActive() && $this->handling->problems($d) !== [])->count(),
            ],
            'can' => ['designate' => $this->organizationOptions($user, $locale) !== []],
        ]);
    }

    /** An institution's active work units not yet designated for this module, for the designation form. */
    public function unitOptions(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $organizationId = (string) $request->query('organization_id', '');
        abort_unless($organizationId !== '' && $this->handling->canManage($user, $organizationId), 403);
        $taken = PublicCaseHandlingUnit::query()->where('organization_id', $organizationId)->where('case_type', $this->routeType($request))
            ->pluck('organization_unit_id')->map(fn ($id) => (string) $id)->all();
        $locale = app()->getLocale();

        return response()->json(['data' => collect($this->units->forOrganization($organizationId))
            ->reject(fn ($unit) => in_array($unit->unitUuid, $taken, true))
            ->map(fn ($unit) => ['id' => $unit->unitUuid, 'name' => $this->name($unit->nameEn, $unit->nameAm, $locale)])
            ->sortBy('name')->values()->all()]);
    }

    /** The unit's own staff (and of the units beneath it) who are not yet members, for the add-member popup. */
    public function staff(Request $request, PublicCaseHandlingUnit $unit): JsonResponse
    {
        $this->assertOwnModule($request, $unit);
        abort_unless($this->handling->canManage($this->user($request), (string) $unit->organization_id), 403);
        $locale = app()->getLocale();

        return response()->json(['data' => array_map(function ($employee) use ($locale): array {
            $context = $employee->currentAssignmentUuid !== null ? $this->assignments->currentFor($employee->employeeUuid) : null;
            $position = $context?->positionUuid !== null ? $this->positions->find($context->positionUuid) : null;
            $placed = $context?->organizationUnitUuid !== null ? $this->units->find($context->organizationUnitUuid) : null;

            return [
                'id' => $employee->employeeUuid,
                'name' => $employee->fullName ?? $employee->nameEn ?? $employee->employeeNumber,
                'employee_number' => $employee->employeeNumber,
                'position' => $position === null ? null : $this->name($position->titleEn, $position->titleAm, $locale),
                'unit' => $placed === null ? null : $this->name($placed->nameEn, $placed->nameAm, $locale),
            ];
        }, $this->handling->candidates($unit))]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'organization_unit_id' => ['required', 'uuid', Rule::exists('organization_units', 'id')->where('organization_id', (string) $request->input('organization_id'))],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_am' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->handling->designate($this->user($request), [...$data, 'case_type' => $this->routeType($request)]);

        return redirect()->route(self::indexRoute($this->routeType($request)))->with('success', __('service-feedback.handling_unit_saved'));
    }

    public function update(Request $request, PublicCaseHandlingUnit $unit): RedirectResponse
    {
        $this->assertOwnModule($request, $unit);
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
        $this->assertOwnModule($request, $unit);
        $data = $request->validate([
            'employee_id' => ['required', 'uuid', 'exists:employees,id'],
            'responsibility' => ['required', Rule::in(PublicCaseHandlingUnitMember::RESPONSIBILITIES)],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'appointment_reference' => ['nullable', 'string', 'max:255'],
            'signature' => ['required', ...self::SIGNATURE_RULES],
        ], ['signature.required' => __('service-feedback.handling_signature_required')]);
        $this->handling->addMember($unit, $this->user($request), [...$data, 'signature' => $request->file('signature')]);

        return redirect()->route(self::indexRoute((string) $unit->case_type))->with('success', __('service-feedback.handling_member_saved'));
    }

    public function updateMember(Request $request, PublicCaseHandlingUnit $unit, PublicCaseHandlingUnitMember $member): RedirectResponse
    {
        $this->assertOwnModule($request, $unit);
        $data = $request->validate([
            'responsibility' => ['required', Rule::in(PublicCaseHandlingUnitMember::RESPONSIBILITIES)],
            'appointment_reference' => ['nullable', 'string', 'max:255'],
            'signature' => ['nullable', ...self::SIGNATURE_RULES],
        ]);
        $this->handling->updateMember($unit, $member, $this->user($request), [...$data, 'signature' => $request->file('signature')]);

        return redirect()->route(self::indexRoute((string) $unit->case_type))->with('success', __('service-feedback.handling_member_saved'));
    }

    public function endMember(Request $request, PublicCaseHandlingUnit $unit, PublicCaseHandlingUnitMember $member): RedirectResponse
    {
        $this->assertOwnModule($request, $unit);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->handling->endMember($unit, $member, $this->user($request), $data['reason']);

        return back()->with('success', __('service-feedback.handling_member_ended_saved'));
    }

    /** The signature registered with a member's term; private, for the unit's administrators. */
    public function signature(Request $request, PublicCaseHandlingUnit $unit, PublicCaseHandlingUnitMember $member): StreamedResponse
    {
        $this->assertOwnModule($request, $unit);
        $user = $this->user($request);
        abort_unless($member->handling_unit_id === $unit->getKey() && $user->hasAnyPermission([PublicFeedbackRoutingService::PERMISSION])
            && ($this->scope->isUnrestricted($user) || $this->scope->canAccessOrganization($user, (string) $unit->organization_id)), 404);
        $path = (string) $member->signature_path;
        abort_unless(preg_match('#^employee-signatures/[a-zA-Z0-9-]+\.(png|jpg|jpeg|webp)$#D', $path) === 1 && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** The unit page of a module: Feedback, Tip-off or Complaint Unit. */
    public static function indexRoute(string $caseType): string
    {
        return (PublicCaseHandlingUnit::ROUTES[$caseType] ?? PublicCaseHandlingUnit::ROUTES['suggestion']).'.index';
    }

    /** The module this address belongs to (route default `case_type`). */
    private function routeType(Request $request): string
    {
        $type = (string) $request->route('case_type');

        return in_array($type, PublicCaseHandlingUnit::CASE_TYPES, true) ? $type : 'suggestion';
    }

    /** A unit is reached only through its own module's address. */
    private function assertOwnModule(Request $request, PublicCaseHandlingUnit $unit): void
    {
        abort_unless($unit->case_type === $this->routeType($request), 404);
    }

    /** @return array<string, mixed> */
    private function memberRow(PublicCaseHandlingUnit $d, PublicCaseHandlingUnitMember $m, string $locale): array
    {
        $employee = $this->employees->findByUuid((string) $m->employee_id);
        $context = $this->assignments->currentFor((string) $m->employee_id);
        $position = $context?->positionUuid !== null ? $this->positions->find($context->positionUuid) : null;
        $placedUnit = $context?->organizationUnitUuid !== null ? $this->units->find($context->organizationUnitUuid) : null;

        return [
            'id' => $m->getKey(),
            'employee_id' => $m->employee_id,
            'name' => $employee?->fullName ?? $employee?->nameEn,
            'employee_number' => $employee?->employeeNumber,
            'position' => $position === null ? null : $this->name($position->titleEn, $position->titleAm, $locale),
            'placed_unit' => $placedUnit === null ? null : $this->name($placedUnit->nameEn, $placedUnit->nameAm, $locale),
            'responsibility' => $m->responsibility,
            'effective_from' => $m->effective_from?->toDateString(),
            'effective_to' => $m->effective_to?->toDateString(),
            'appointment_reference' => $m->appointment_reference,
            'placed' => $this->handling->isPlacedIn((string) $m->employee_id, $d),
            'has_account' => User::query()->where('employee_id', $m->employee_id)->where('status', 'active')->exists(),
            'signature_url' => route(PublicCaseHandlingUnit::ROUTES[$d->case_type].'.members.signature', [$d, $m]),
            'signature_recorded_at' => $m->signature_recorded_at?->toIso8601String(),
        ];
    }

    private function name(?string $en, ?string $am, string $locale): ?string
    {
        return $locale === 'am' ? ($am ?: $en) : ($en ?: $am);
    }

    /** @return list<array{id: string, name: string}> */
    private function organizationOptions(User $user, string $locale): array
    {
        $rows = [];
        foreach ($this->organizations->activeFor($user, 1000) as $org) {
            $rows[] = ['id' => $org->organizationUuid, 'name' => (string) $this->name($org->nameEn, $org->nameAm, $locale)];
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
            $names[(string) $id] = $org === null ? null : $this->name($org->nameEn, $org->nameAm, $locale);
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
