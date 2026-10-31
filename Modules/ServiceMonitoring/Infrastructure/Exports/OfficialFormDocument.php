<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Infrastructure\Exports;

use App\Models\Organization;
use App\Models\OrganizationLetterhead;
use App\Models\OrganizationSeal;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Modules\ServiceMonitoring\Application\Services\ConsolidationService;
use Modules\ServiceMonitoring\Application\Services\FiscalCalendar;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringSettings;
use Modules\ServiceMonitoring\Domain\Enums\FormType;
use Modules\ServiceMonitoring\Domain\Enums\ReportRange;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\FiscalYear;
use Modules\ServiceMonitoring\Domain\Models\MonitoringExport;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\Submission;
use Throwable;

/**
 * The content of one official-form export (D25), rendered by both the XLSX
 * workbook and the PDF view: letterhead and seal of the issuing
 * organization when it has them, the form title, the range, who generated
 * it, the masking and snapshot notices, and the tables of each section.
 *
 * Names appear only when the export asked for them AND the requester holds
 * accountability.export_named; otherwise the role category is printed.
 */
final class OfficialFormDocument
{
    public function __construct(
        private readonly ConsolidationService $consolidation,
        private readonly ServiceMonitoringAccess $access,
        private readonly ServiceMonitoringSettings $settings,
        private readonly FiscalCalendar $calendar,
    ) {}

    /** @return array<string, mixed> */
    public function build(MonitoringExport $export, User $requester): array
    {
        $locale = app()->getLocale();
        $named = $export->named && $this->access->canExportNamed($requester);
        $year = FiscalYear::query()->findOrFail($export->fiscal_year_id);
        $range = $export->range;
        $period = $year->periods()->where('quarter', $export->quarter)->firstOrFail();

        $submission = null;
        $issuer = null;
        [$types, $report, $scopeLabel] = match ($export->scope_type) {
            MonitoringExport::SCOPE_SUBMISSION => (function () use ($export, $named, $range, $year, &$submission, &$issuer): array {
                $submission = Submission::query()->with(['period.fiscalYear', 'organization'])->findOrFail($export->scope_id);
                $issuer = $submission->organization;
                $report = $range === ReportRange::Quarter
                    ? $this->consolidation->forSubmission($submission, $named)
                    : $this->consolidation->report($year, $range, $submission->period->quarter, [$submission->participant_id], $named);

                return [[$submission->form_type], $report, (string) __('service-monitoring.export.scope_submission')];
            })(),
            MonitoringExport::SCOPE_SUB_CITY => (function () use ($export, $named, $range, $year, &$issuer): array {
                $subCity = Participant::query()->with('organization')->findOrFail($export->scope_id);
                $issuer = $subCity->organization;

                return [[FormType::B], $this->consolidation->report($year, $range, $export->quarter, $this->consolidation->subCityParticipantIds($subCity), $named),
                    (string) __('service-monitoring.export.scope_sub_city')];
            })(),
            default => (function () use ($export, $named, $range, $year, &$issuer): array {
                $issuer = Organization::query()->find($this->settings->row()->owner_organization_id);

                return [[FormType::A, FormType::B], $this->consolidation->report($year, $range, $export->quarter, null, $named), (string) __('service-monitoring.export.scope_city')];
            })(),
        };

        $sheets = [];
        foreach (['section_1', 'summary', 'section_2', 'section_3', 'section_4'] as $key) {
            $tables = [];
            foreach ($types as $type) {
                $sections = OfficialFormLayout::sections($type, $period->form_version, (int) $year->ec_year, $report, $named, $locale);
                foreach ($sections[$key] as $table) {
                    $tables[] = count($types) > 1 ? ['subtitle' => trim(($table['subtitle'] ?? '').' — '.$type->label($locale), ' —')] + $table : $table;
                }
            }
            $sheets[] = ['key' => $key, 'title' => (string) __("service-monitoring.export.sheet_{$key}"), 'tables' => $tables];
        }

        $letterhead = $issuer !== null ? OrganizationLetterhead::query()->where('organization_id', $issuer->id)->first() : null;
        $issuerName = $issuer === null ? null : (string) (($locale === 'am' ? $issuer->name_am : null) ?: $issuer->name_en);
        $definition = FormDefinition::describe($types[0], $period->form_version, (int) $year->ec_year, $locale);
        $preamble = array_values(array_filter([
            $letterhead !== null ? (string) ($locale === 'am' ? ($letterhead->header_line_am ?: $letterhead->header_line_en) : ($letterhead->header_line_en ?: $letterhead->header_line_am)) : null,
            $issuerName !== null ? $scopeLabel.': '.$issuerName : $scopeLabel,
            count($types) > 1 ? (string) __('service-monitoring.module') : $definition['title'],
            (string) __('service-monitoring.export.range_line', [
                'range' => $range === ReportRange::Quarter ? $period->label() : $range->label(),
                'year' => $year->ec_year,
                'from' => $this->calendar->display($report['from']),
                'to' => $this->calendar->display($report['to']),
            ]),
            (string) __('service-monitoring.export.generated', ['at' => $this->calendar->display(now()->toDateString()).' '.now()->setTimezone('Africa/Addis_Ababa')->format('H:i'), 'user' => $requester->name]),
            $submission?->attested_at !== null ? (string) __('service-monitoring.export.attested', ['name' => $submission->attester_name_snapshot, 'title' => $submission->attester_title_snapshot ?? '', 'at' => $this->calendar->display($submission->attested_at->toDateString())]) : null,
            $named ? null : (string) __('service-monitoring.export.masked'),
            $report['from_snapshot'] ? (string) __('service-monitoring.export.locked_source') : null,
        ]));

        return [
            'title' => $definition['title'],
            'preamble' => $preamble,
            'sheets' => $sheets,
            'general_notes' => $definition['general_notes'],
            'notes_title' => (string) __('service-monitoring.form.general_notes_title'),
            'footer' => $letterhead !== null ? (string) ($locale === 'am' ? ($letterhead->footer_am ?: $letterhead->footer_en) : ($letterhead->footer_en ?: $letterhead->footer_am)) : null,
            'seal' => $issuer !== null ? $this->seal($issuer->id) : null,
            'named' => $named,
            'rows' => count($report['rows']),
            'register_rows' => count($report['register']),
        ];
    }

    /** The organization's approved seal as a data URI, for the PDF; null when it has none. */
    private function seal(string $organizationId): ?string
    {
        $seal = OrganizationSeal::query()->where('organization_id', $organizationId)->where('status', 'active')->latest('effective_from')->first();
        if ($seal === null) {
            return null;
        }
        try {
            $content = Storage::disk($seal->disk ?: 'local')->get($seal->path);
        } catch (Throwable) {
            return null;
        }

        return $content === null ? null : 'data:'.($seal->mime_type ?: 'image/png').';base64,'.base64_encode($content);
    }
}
