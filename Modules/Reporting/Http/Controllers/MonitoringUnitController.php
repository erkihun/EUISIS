<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\UseCases\ManageMonitoringUnits;
use Modules\Reporting\Contracts\Data\ReportingRoles;

/**
 * The work unit that follows up reports in each institution: its own page,
 * laid out as the Grievance module lays out its teams. One line per
 * designated unit, opening to the people placed in it.
 */
final class MonitoringUnitController extends ReportingController
{
    public function index(Request $request, ManageMonitoringUnits $units): Response
    {
        $actor = $this->actor($request);
        if (! $actor->can(ReportingRoles::MANAGE_SETTINGS)) {
            ReportingGuard::require($actor, ReportingRoles::INSPECT_AUDIT);
        }
        $search = trim((string) $request->query('search', ''));

        return Inertia::render('modules/reporting/MonitoringUnits', [
            'units' => $units->page($actor, $search)->withQueryString(),
            'summary' => $units->summary($actor),
            'filters' => ['search' => $search],
            'can' => ['manage' => $actor->can(ReportingRoles::MANAGE_SETTINGS)],
        ]);
    }

    /** Names the work unit that follows up reports in one institution; naming another replaces it. */
    public function store(Request $request, ManageMonitoringUnits $units): RedirectResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'uuid'], 'organization_unit_id' => ['required', 'uuid']]);
        $units->designate($this->actor($request), $data['organization_id'], $data['organization_unit_id']);

        return back()->with('success', __('reporting::messages.flash.saved'));
    }

    public function destroy(Request $request, string $organization, ManageMonitoringUnits $units): RedirectResponse
    {
        $units->remove($this->actor($request), $organization);

        return back()->with('success', __('reporting::messages.flash.saved'));
    }
}
