<?php

declare(strict_types=1);

namespace App\Jobs\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Exports\OrganizationDesign\DesignReportExport;
use App\Models\OrganizationDesignReportExport;
use App\Services\OrganizationDesign\OrganizationDesignAudit;
use App\Services\OrganizationDesign\OrganizationDesignReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Throwable;

/**
 * Builds one requested report export on the queue, as the user who asked
 * for it (so the organization scope is theirs), and stores the file on the
 * private disk. The row records where it landed, or why it failed.
 */
class ExportOrganizationDesignReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public OrganizationDesignReportExport $export) {}

    public function handle(OrganizationDesignReportService $reports, OrganizationDesignAudit $audit): void
    {
        $export = $this->export->fresh();
        if ($export === null || $export->status !== 'queued') {
            return;
        }
        $requester = $export->requester;
        if ($requester === null) {
            $export->forceFill(['status' => 'failed', 'error' => 'requester missing', 'completed_at' => now()])->save();

            return;
        }
        try {
            $result = $reports->run($export->report, $requester, ['organization_id' => $export->organization_id, ...($export->filters ?? [])]);
            $labels = collect($result['columns'])->mapWithKeys(fn ($c) => [$c => __("organization-design.reports.columns.{$c}") === "organization-design.reports.columns.{$c}" ? $c : __("organization-design.reports.columns.{$c}")])->all();
            $filename = 'organization-design-'.str_replace('_', '-', $export->report).'-'.now()->format('Ymd-His').'.'.$export->format;
            $path = 'organization-design/exports/'.$export->id.'.'.$export->format;
            ExcelFacade::store(new DesignReportExport($result, $labels, $export->format === 'csv'), $path, $export->disk, $export->format === 'csv' ? Excel::CSV : Excel::XLSX);
            $export->forceFill(['status' => 'ready', 'path' => $path, 'filename' => $filename, 'row_count' => count($result['rows']), 'completed_at' => now(), 'error' => null])->save();
            $audit->record(AuditEventType::ReportExported, $requester, $export, ['report' => $export->report, 'format' => $export->format, 'rows' => count($result['rows']), 'organization_id' => $export->organization_id]);
        } catch (Throwable $e) {
            $export->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'completed_at' => now()])->save();
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $this->export->fresh()?->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'completed_at' => now()])->save();
    }
}
