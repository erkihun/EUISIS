<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Infrastructure\Jobs;

use App\Enums\AuditEventType;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\ExportStatus;
use Modules\ServiceMonitoring\Domain\Models\MonitoringExport;
use Modules\ServiceMonitoring\Infrastructure\Exports\OfficialFormDocument;
use Modules\ServiceMonitoring\Infrastructure\Exports\OfficialFormWorkbook;
use Throwable;

/**
 * Builds one requested official-form export on the queue, as the user who
 * asked for it (their scope and the named-data grant apply), onto the
 * private disk, and audits it (the ExportPublicFeedbackReport pattern).
 */
final class GenerateServiceMonitoringExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public MonitoringExport $export) {}

    public function handle(OfficialFormDocument $documents, ServiceMonitoringAudit $audit): void
    {
        $export = $this->export->fresh();
        if ($export === null || $export->status !== ExportStatus::Queued) {
            return;
        }
        $requester = $export->requester;
        if ($requester === null) {
            $export->forceFill(['status' => ExportStatus::Failed, 'error' => 'requester missing', 'completed_at' => now()])->save();

            return;
        }

        $previous = app()->getLocale();
        app()->setLocale(in_array($export->locale, ['en', 'am'], true) ? $export->locale : $previous);
        try {
            $document = $documents->build($export, $requester);
            $path = 'service-monitoring/exports/'.$export->id.'.'.$export->format;
            $filename = 'service-delivery-report-'.$export->scope_type.'-'.$export->range->value.'-q'.$export->quarter.'-'.now()->format('Ymd-His').'.'.$export->format;
            if ($export->format === 'pdf') {
                Storage::disk('local')->put($path, Pdf::loadView('service-monitoring.official-form', $document)->setPaper('a4', 'landscape')->output());
            } else {
                ExcelFacade::store(new OfficialFormWorkbook($document), $path, 'local', Excel::XLSX);
            }
            $export->forceFill(['status' => ExportStatus::Ready, 'disk' => 'local', 'path' => $path, 'filename' => $filename, 'completed_at' => now()])->save();

            $audit->record(AuditEventType::ServiceMonitoringExported, $requester, $export, null, [
                'scope_type' => $export->scope_type, 'scope_id' => $export->scope_id, 'range' => $export->range->value, 'quarter' => $export->quarter,
                'format' => $export->format, 'named' => $document['named'], 'rows' => $document['rows'], 'register_rows' => $document['register_rows'],
            ]);
        } catch (Throwable $exception) {
            $export->forceFill(['status' => ExportStatus::Failed, 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()])->save();

            throw $exception;
        } finally {
            app()->setLocale($previous);
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->export->fresh()?->forceFill(['status' => ExportStatus::Failed, 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()])->save();
    }
}
