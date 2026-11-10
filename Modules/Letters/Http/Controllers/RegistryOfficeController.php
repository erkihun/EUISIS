<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Controllers;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Letters\Application\Services\LetterLookups;
use Modules\Letters\Application\Services\LettersAccess;
use Modules\Letters\Application\Services\LettersAudit;
use Modules\Letters\Domain\Models\LetterRegistryOffice;

/**
 * Registry offices (መዝገብ ቤት, ADR 0037 §14): a unit of the institution's
 * structure is designated as its registry. The page shows who works in that
 * unit and whether they can register and forward letters, so the institution
 * sees at once when its registry cannot do its work. Access itself stays with
 * roles: designating a unit grants nobody a permission.
 */
final class RegistryOfficeController extends Controller
{
    private const PERMISSION = 'letters.standards.manage';

    public function __construct(
        private readonly LettersAccess $access,
        private readonly LetterLookups $lookups,
        private readonly LettersAudit $audit,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $organizations = $this->lookups->actingOrganizations($user, self::PERMISSION);
        abort_if($organizations === [], 403);

        $requested = is_string($request->query('organization_id')) ? $request->query('organization_id') : '';
        $selected = collect($organizations)->contains('id', $requested) ? $requested : (string) $organizations[0]['id'];
        $offices = LetterRegistryOffice::query()->with('unit:id,code,name_en,name_am,status')->where('organization_id', $selected)->orderBy('created_at')->get();
        $taken = $offices->pluck('organization_unit_id');

        return Inertia::render('Letters/RegistryOffices', [
            'organizations' => $organizations,
            'selected_organization_id' => $selected,
            'offices' => $offices->map(fn (LetterRegistryOffice $office): array => $this->present($office))->values()->all(),
            // Units of the structure not yet designated.
            'units' => array_values(array_filter($this->lookups->units($selected), fn (array $unit): bool => ! $taken->contains($unit['id']))),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'uuid'],
            'organization_unit_id' => ['required', 'uuid', Rule::exists('organization_units', 'id')->where('organization_id', (string) $request->input('organization_id'))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $user = $request->user();
        abort_unless($this->access->canActFor($user, self::PERMISSION, $data['organization_id']), 403);
        if (LetterRegistryOffice::query()->where('organization_id', $data['organization_id'])->where('organization_unit_id', $data['organization_unit_id'])->exists()) {
            throw ValidationException::withMessages(['organization_unit_id' => __('letters.validation.registry_office_taken')]);
        }

        $office = LetterRegistryOffice::query()->create([...$data, 'status' => 'active', 'created_by' => $user->id, 'updated_by' => $user->id]);
        $this->audit->record(AuditEventType::LetterStandardsSaved, $user, $office, $office->organization_id, ['registry_office' => 'designated', 'unit_id' => $office->organization_unit_id]);

        return back()->with('success', __('letters.flash.registry_office_saved'));
    }

    public function update(Request $request, LetterRegistryOffice $registryOffice): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->access->canActFor($user, self::PERMISSION, $registryOffice->organization_id), 403);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(LetterRegistryOffice::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $registryOffice->fill([...$data, 'updated_by' => $user->id])->save();
        $this->audit->record(AuditEventType::LetterStandardsSaved, $user, $registryOffice, $registryOffice->organization_id, ['registry_office' => 'updated', 'status' => $registryOffice->status]);

        return back()->with('success', __('letters.flash.registry_office_saved'));
    }

    public function destroy(Request $request, LetterRegistryOffice $registryOffice): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->access->canActFor($user, self::PERMISSION, $registryOffice->organization_id), 403);

        $registryOffice->delete();
        $this->audit->record(AuditEventType::LetterStandardsSaved, $user, $registryOffice, $registryOffice->organization_id, ['registry_office' => 'removed', 'unit_id' => $registryOffice->organization_unit_id]);

        return back()->with('success', __('letters.flash.registry_office_removed'));
    }

    /** @return array<string, mixed> */
    private function present(LetterRegistryOffice $office): array
    {
        $staff = $this->lookups->staffOf($office->organization_unit_id)->map(fn (User $person): array => [
            'id' => $person->id,
            'name' => $person->name,
            'position' => $person->employee?->currentAssignment?->position?->only(['title_en', 'title_am']),
            'can_register' => $this->access->canActFor($person, 'letters.register', $office->organization_id),
            'can_route' => $this->access->canActFor($person, 'letters.route', $office->organization_id),
        ])->values();

        // What stops this registry from doing its work, in the order it should be fixed.
        $problems = [];
        $unitStatus = $office->unit?->status;
        // The unit's status is cast to an enum; compare its value.
        if (($unitStatus instanceof \BackedEnum ? $unitStatus->value : $unitStatus) !== 'active') {
            $problems[] = 'unit_inactive';
        }
        if ($staff->isEmpty()) {
            $problems[] = 'no_staff';
        } else {
            if (! $staff->contains('can_register', true)) {
                $problems[] = 'no_registrar';
            }
            if (! $staff->contains('can_route', true)) {
                $problems[] = 'no_router';
            }
        }

        return [
            'id' => $office->id,
            'status' => $office->status,
            'note' => $office->note,
            'unit' => $office->unit?->only(['id', 'code', 'name_en', 'name_am']),
            'staff' => $staff->all(),
            'problems' => $office->status === 'active' ? $problems : [],
        ];
    }
}
