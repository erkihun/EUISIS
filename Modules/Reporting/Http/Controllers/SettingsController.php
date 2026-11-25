<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Reporting\Application\Ports\AuditRecorder;
use Modules\Reporting\Application\Ports\PdfConverter;
use Modules\Reporting\Application\Support\MetricSources;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Application\UseCases\ManageMonitoringUnits;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Infrastructure\Persistence\OperationalHealth;

/**
 * The module's own settings, including the maintenance switch, and its
 * operational health: scheduler runs, events not yet relayed, failed files
 * and notices.
 */
final class SettingsController extends ReportingController
{
    public function edit(Request $request, ReportingSettings $settings, OperationalHealth $health, PdfConverter $pdf, MetricSources $metrics, ManageMonitoringUnits $units): Response
    {
        $actor = $this->actor($request);
        if (! $actor->can(ReportingRoles::MANAGE_SETTINGS)) {
            ReportingGuard::require($actor, ReportingRoles::INSPECT_AUDIT);
        }

        return Inertia::render('modules/reporting/Settings', [
            // Not "settings": that name is the layout's own shared prop (system settings) on every page.
            'report_setting' => $settings->all(),
            'health' => $health->report(),
            'capabilities' => [
                'pdf_preview' => $pdf->isAvailable(),
                'malware_scanner' => (array) config('reporting.scanner.command') !== [],
                'metric_sources' => $metrics->status(),
            ],
            'monitoring_units' => $units->list($actor),
            'can' => ['manage' => $actor->can(ReportingRoles::MANAGE_SETTINGS)],
        ]);
    }

    /** Names the work unit that follows up reports in one institution. */
    public function designateUnit(Request $request, ManageMonitoringUnits $units): RedirectResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'uuid'], 'organization_unit_id' => ['required', 'uuid']]);
        $units->designate($this->actor($request), $data['organization_id'], $data['organization_unit_id']);

        return back()->with('success', __('reporting::messages.flash.saved'));
    }

    public function removeUnit(Request $request, string $organization, ManageMonitoringUnits $units): RedirectResponse
    {
        $units->remove($this->actor($request), $organization);

        return back()->with('success', __('reporting::messages.flash.saved'));
    }

    /** The one write the maintenance switch does not block: it is how the module is switched back on. */
    public function update(Request $request, ReportingSettings $settings, AuditRecorder $audit): RedirectResponse
    {
        $actor = $this->actor($request);
        ReportingGuard::require($actor, ReportingRoles::MANAGE_SETTINGS);
        $data = $request->validate([
            'maintenance' => ['required', 'boolean'],
            'max_upload_kb' => ['required', 'integer', 'min:512', 'max:102400'],
            'email_notifications' => ['required', 'boolean'],
            'document_font' => ['required', 'string', 'max:80', 'regex:/^[\pL\pN .\-]+$/u'],
            'reminder_rules' => ['required', 'array'],
            'reminder_rules.days_before' => ['array', 'max:6'],
            'reminder_rules.days_before.*' => ['integer', 'min:1', 'max:60'],
            'reminder_rules.on_due_date' => ['required', 'boolean'],
            'reminder_rules.when_overdue' => ['required', 'boolean'],
            'reminder_rules.escalate_after_working_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);
        $before = $settings->all();
        foreach ($data as $key => $value) {
            $settings->put($key, $value, $actor->userId);
        }
        $audit->record('settings_changed', $actor, 'reporting.settings', 'settings', null, ['before' => $before, 'after' => $data]);

        return back()->with('success', __('reporting::messages.flash.saved'));
    }
}
