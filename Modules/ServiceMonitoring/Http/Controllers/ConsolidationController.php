<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ServiceMonitoring\Application\Actions\CertifySubCityConsolidation;
use Modules\ServiceMonitoring\Application\Actions\RecordNonSubmission;
use Modules\ServiceMonitoring\Application\Services\ComplianceService;
use Modules\ServiceMonitoring\Application\Services\ConsolidationService;
use Modules\ServiceMonitoring\Application\Services\ParticipantResolver;
use Modules\ServiceMonitoring\Application\Services\PeriodDirectory;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\FormType;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Enums\ReportRange;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\Certification;
use Modules\ServiceMonitoring\Domain\Models\FiscalYear;
use Modules\ServiceMonitoring\Domain\Models\MonitoringExport;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;
use Modules\ServiceMonitoring\Http\Requests\ReasonRequest;

/**
 * Sub-city and city consolidations for a quarter or the 6-month, 9-month and
 * annual ranges (D3), with certification (sub-city) and the lock (city).
 */
final class ConsolidationController extends Controller
{
    public function __construct(
        private readonly ConsolidationService $consolidation,
        private readonly ComplianceService $compliance,
        private readonly ParticipantResolver $participants,
        private readonly PeriodDirectory $periods,
        private readonly ServiceMonitoringAccess $access,
        private readonly ServiceMonitoringAudit $audit,
    ) {}

    public function subCity(Request $request): Response
    {
        $user = $request->user();
        $subCities = $this->access->scopeParticipants(Participant::query(), $user)->where('level', ReportingLevel::SubCity->value)
            ->whereNull('active_to')->with('organization:id,name_en,name_am')->get()
            ->filter(fn (Participant $p): bool => $this->access->canConsolidateSubCity($user, $p))->values();
        abort_unless($user->can('service_monitoring.consolidate'), 403);

        $selected = $subCities->firstWhere('id', $request->string('participant_id')->toString()) ?? $subCities->first();
        [$year, $quarter, $range] = $this->selection($request);
        $period = $year?->periods()->where('quarter', $quarter)->first();

        $props = ['sub_cities' => $subCities->map(fn (Participant $p): array => ['id' => $p->id, 'name_en' => $p->organization?->name_en, 'name_am' => $p->organization?->name_am])->all()];
        if ($selected === null || $year === null || $period === null) {
            return Inertia::render('ServiceMonitoring/Consolidation/SubCity', $props + $this->empty($year, $quarter, $range));
        }

        $named = $user->can('service_monitoring.accountability.view_named') && $this->access->reaches($user, $selected->organization_id);
        $report = $this->consolidation->report($year, $range, $quarter, $this->consolidation->subCityParticipantIds($selected), $named);
        $this->auditNamed($user, $named, $report, $selected->organization_id);
        $woredas = $this->participants->woredasOf($selected, $period);
        $status = $this->compliance->forPeriod($period, $woredas->concat([$selected]));
        $certification = Certification::query()->where('period_id', $period->id)->where('participant_id', $selected->id)->with('certifier:id,name')->first();

        return Inertia::render('ServiceMonitoring/Consolidation/SubCity', $props + [
            'selection' => ['fiscal_year_id' => $year->id, 'quarter' => $quarter, 'range' => $range->value, 'participant_id' => $selected->id],
            'years' => $this->periods->years(),
            'period' => $this->periods->period($period),
            'definition' => FormDefinition::describe(FormType::B, $period->form_version, (int) $year->ec_year, app()->getLocale()),
            'report' => $report,
            'woredas' => $woredas->map(fn (Participant $p): array => [
                'participant_id' => $p->id, 'level' => $p->level->value,
                'organization' => ['name_en' => $p->organization?->name_en, 'name_am' => $p->organization?->name_am],
                'compliance' => $status[$p->id],
            ])->values()->all(),
            'certification' => $certification === null ? null : ['certified_at' => $certification->certified_at->toIso8601String(), 'certified_by' => $certification->certifier?->name, 'snapshot_id' => $certification->snapshot_id],
            'exports' => $this->exports($user),
            'can' => [
                'certify' => $this->access->canConsolidateSubCity($user, $selected) && ! $period->isLocked() && $range === ReportRange::Quarter,
                'export' => $this->access->canExport($user),
                'export_named' => $this->access->canExportNamed($user),
                'record_not_submitted' => $user->can('service_monitoring.consolidate') && ! $period->isLocked(),
            ],
        ]);
    }

    public function city(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->access->canConsolidateCity($user) || ($this->access->canViewReports($user) && $this->access->isCitywide($user)), 403);
        [$year, $quarter, $range] = $this->selection($request);
        $period = $year?->periods()->where('quarter', $quarter)->first();
        if ($year === null || $period === null) {
            return Inertia::render('ServiceMonitoring/Consolidation/City', $this->empty($year, $quarter, $range));
        }

        $named = $user->can('service_monitoring.accountability.view_named') && $this->access->isCitywide($user);
        $report = $this->consolidation->report($year, $range, $quarter, null, $named);
        $this->auditNamed($user, $named, $report, null);
        $subCities = $this->participants->subCities($period);
        $certified = Certification::query()->where('period_id', $period->id)->pluck('certified_at', 'participant_id');

        return Inertia::render('ServiceMonitoring/Consolidation/City', [
            'selection' => ['fiscal_year_id' => $year->id, 'quarter' => $quarter, 'range' => $range->value],
            'years' => $this->periods->years(),
            'period' => $this->periods->period($period),
            'definitions' => [
                'A' => FormDefinition::describe(FormType::A, $period->form_version, (int) $year->ec_year, app()->getLocale()),
                'B' => FormDefinition::describe(FormType::B, $period->form_version, (int) $year->ec_year, app()->getLocale()),
            ],
            'report' => $report,
            'sub_cities' => $subCities->map(fn (Participant $p): array => [
                'participant_id' => $p->id, 'name_en' => $p->organization?->name_en, 'name_am' => $p->organization?->name_am,
                'certified_at' => isset($certified[$p->id]) ? (string) $certified[$p->id] : null,
            ])->values()->all(),
            'exports' => $this->exports($user),
            'can' => [
                'lock' => $user->can('lock', $period) && $range === ReportRange::Quarter,
                'reopen' => $user->can('reopen', $period),
                'export' => $this->access->canExport($user),
                'export_named' => $this->access->canExportNamed($user),
            ],
        ]);
    }

    public function certify(Request $request, ReportingPeriod $period, Participant $participant, CertifySubCityConsolidation $certify): RedirectResponse
    {
        $this->authorize('consolidate', $participant);
        $certify->execute($period, $participant, $request->user());

        return back()->with('success', __('service-monitoring.flash.certified'));
    }

    public function notSubmitted(ReasonRequest $request, ReportingPeriod $period, Participant $participant, RecordNonSubmission $record): RedirectResponse
    {
        $this->authorize('recordNonSubmission', $participant);
        $record->execute($period, $participant, (string) $request->validated('reason'), $request->user());

        return back()->with('success', __('service-monitoring.flash.not_submitted'));
    }

    /** @return array{0: ?FiscalYear, 1: int, 2: ReportRange} */
    private function selection(Request $request): array
    {
        $current = $this->periods->current();
        $year = FiscalYear::query()->find($request->string('fiscal_year_id')->toString()) ?? $current?->fiscalYear ?? FiscalYear::query()->orderByDesc('ec_year')->first();
        $quarter = max(1, min(4, (int) ($request->input('quarter') ?: ($current?->fiscal_year_id === $year?->id ? $current?->quarter : 1) ?: 1)));
        $range = ReportRange::tryFrom($request->string('range')->toString()) ?? ReportRange::Quarter;

        return [$year, $quarter, $range];
    }

    /** @return array<string, mixed> */
    private function empty(?FiscalYear $year, int $quarter, ReportRange $range): array
    {
        return ['selection' => ['fiscal_year_id' => $year?->id, 'quarter' => $quarter, 'range' => $range->value], 'years' => $this->periods->years(), 'report' => null];
    }

    /** @param array<string, mixed> $report */
    private function auditNamed(User $user, bool $named, array $report, ?string $organizationId): void
    {
        if ($named && $report['register'] !== []) {
            $this->audit->record(AuditEventType::ServiceMonitoringNamedDataViewed, $user, null, $organizationId,
                ['view' => 'consolidation', 'range' => $report['range'], 'quarters' => $report['quarters'], 'rows' => count($report['register'])]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function exports(User $user): array
    {
        return MonitoringExport::query()->where('requested_by', $user->id)->latest()->limit(10)->get()->map(fn (MonitoringExport $e): array => [
            'id' => $e->id, 'scope_type' => $e->scope_type, 'range' => $e->range->value, 'quarter' => $e->quarter, 'format' => $e->format,
            'status' => $e->status->value, 'filename' => $e->filename, 'created_at' => $e->created_at?->toIso8601String(),
            'download_url' => $e->isReady() ? route('service-monitoring.exports.download', $e) : null,
        ])->all();
    }
}
