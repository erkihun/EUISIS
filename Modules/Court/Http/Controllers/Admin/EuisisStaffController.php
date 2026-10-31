<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\EuisisOrganization;
use Modules\Court\Domain\Models\EuisisOrganizationUnit;
use Modules\Court\Domain\Models\EuisisStaffNotice;
use Modules\Court\Domain\Models\EuisisStaffUnit;
use Modules\Court\Domain\Models\CourtStaffMember as User;
use Modules\Court\Application\Services\Euisis\EuisisClient;
use Modules\Court\Application\Services\Euisis\EuisisException;
use Modules\Court\Application\Services\Euisis\StaffLink;
use Modules\Court\Application\Services\Euisis\StaffLinkException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Court staff and EUISIS (docs/euisis-integration.md, "Staff accounts"): the
 * roster of the court units' EUISIS employees with their staff accounts, the
 * choice of those units, and linking one account by employee number.
 */
class EuisisStaffController extends Controller
{
    public function __construct(private readonly StaffLink $staff, private readonly EuisisClient $client) {}

    /** Users > EUISIS staff (React, Court/Users/EuisisStaff). */
    public function index(Request $request): Response
    {
        $chosen = EuisisStaffUnit::query()->with(['unit', 'organization'])->get();
        $organizationUuid = (string) $request->query('organization', (string) ($chosen->first()?->organization_uuid ?? ''));

        $roster = null;
        $error = null;
        if ($this->client->enabled() && $chosen->isNotEmpty()) {
            try {
                $roster = $this->staff->roster();
            } catch (EuisisException $e) {
                Log::warning('EUISIS staff roster could not be read', ['error' => $e->getMessage(), 'request_id' => $e->requestId]);
                $error = __('court::euisis_staff.errors.euisis_unavailable');
            }
        }

        return Inertia::render('Court/Users/EuisisStaff', [
            'enabled' => $this->client->enabled(),
            'units' => $chosen->map(fn (EuisisStaffUnit $row): array => [
                'unit_uuid' => (string) $row->unit_uuid,
                'organization_uuid' => (string) $row->organization_uuid,
                'name' => $row->unit !== null ? $this->staff->unitName($row->unit) : (string) $row->unit_uuid,
                'organization' => $row->organization?->displayName(),
                'include_sub_units' => (bool) $row->include_sub_units,
            ])->values()->all(),
            'organizations' => EuisisOrganization::query()->orderBy('name_en')->get()
                ->map(fn (EuisisOrganization $organization): array => ['uuid' => (string) $organization->uuid, 'name' => $organization->displayName()])
                ->values()->all(),
            'organization' => $organizationUuid,
            'unitOptions' => $organizationUuid === '' ? [] : EuisisOrganizationUnit::query()
                ->where('organization_uuid', $organizationUuid)->orderBy('name_en')->get()
                ->map(fn (EuisisOrganizationUnit $unit): array => ['uuid' => (string) $unit->uuid, 'name' => $this->staff->unitName($unit), 'code' => $unit->code])
                ->values()->all(),
            'roster' => $roster,
            'rosterError' => $error,
            'notices' => EuisisStaffNotice::query()->with('user:id,name')->latest()->latest('id')->limit(10)->get()
                ->map(fn (EuisisStaffNotice $notice): array => [
                    'id' => (int) $notice->id,
                    'user_id' => (int) $notice->user_id,
                    'name' => (string) ($notice->user?->name ?? ''),
                    'event' => (string) $notice->event,
                    'reason' => $notice->reason !== null ? (string) __('court::euisis_staff.reasons.'.$notice->reason) : null,
                    'at' => $notice->created_at?->toIso8601String(),
                ])->values()->all(),
            'canManageUnits' => (bool) $request->user()?->hasPermission('settings.manage'),
        ]);
    }

    /** The court's units (needs `settings.manage` too). */
    public function units(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->hasPermission('settings.manage'), 403);

        $data = $request->validate([
            'units' => ['present', 'array', 'max:20'],
            'units.*.unit_uuid' => ['required', 'uuid', 'distinct', Rule::exists('court_euisis_organization_units', 'uuid')],
            'units.*.include_sub_units' => ['required', 'boolean'],
        ]);
        $this->staff->saveUnits(array_map(fn (array $row): array => [
            'unit_uuid' => (string) $row['unit_uuid'],
            'include_sub_units' => (bool) $row['include_sub_units'],
        ], $data['units']));

        return back()->with('success', ['key' => 'court::euisis_staff.flash.units_saved']);
    }

    /** Links one staff account by employee number. */
    public function link(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);
        $data = $request->validate(['employee_number' => ['required', 'string', 'max:50']]);

        try {
            $this->staff->link($user, $data['employee_number']);
        } catch (StaffLinkException $e) {
            return back()->withErrors(['employee_number' => __('court::euisis_staff.errors.'.$e->reason)]);
        } catch (EuisisException $e) {
            Log::warning('EUISIS staff link failed', ['user_id' => $user->id, 'error' => $e->getMessage(), 'request_id' => $e->requestId]);

            return back()->withErrors(['employee_number' => __('court::euisis_staff.errors.euisis_unavailable')]);
        }

        return back()->with('success', ['key' => 'court::euisis_staff.flash.linked']);
    }

    public function unlink(User $user): RedirectResponse
    {
        $this->authorize('update', $user);
        $this->staff->unlink($user);

        return back()->with('success', ['key' => 'court::euisis_staff.flash.unlinked']);
    }
}
