<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure\Jobs;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Exports\PublicFeedbackSubmissionsExport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Modules\Feedback\Application\Services\PublicFeedbackMetrics;
use Modules\Feedback\Domain\Models\PublicFeedbackReportExport;
use Throwable;

/**
 * Builds one requested submissions export on the queue, as the user who
 * asked for it (so their scope and visibility grants apply), onto the
 * private disk (brief §87). The row records where the file landed, or why
 * the build failed.
 */
class ExportPublicFeedbackReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public PublicFeedbackReportExport $export) {}

    public function handle(PublicFeedbackMetrics $metrics, WriteAuditLogAction $audit): void
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

        // English: English labels and Gregorian dates. Amharic: Amharic labels and names, Ethiopian dates.
        $previousLocale = app()->getLocale();
        $locale = in_array($export->locale, ['en', 'am'], true) ? $export->locale : $previousLocale;
        app()->setLocale($locale);

        try {
            $filters = $metrics->filters($export->filters ?? []);
            $query = $metrics->query($requester, $filters);
            $columns = $metrics->exportColumns($requester);
            $rows = $metrics->exportRows($query, $requester, $locale);
            $labels = collect($columns)->mapWithKeys(fn (string $column): array => [$column => (string) __('service-feedback.export_columns.'.$column)])->all();
            $csv = $export->format === 'csv';
            $filename = 'public-feedback-submissions-'.now()->format('Ymd-His').'.'.$export->format;
            $path = 'public-feedback/exports/'.$export->id.'.'.$export->format;

            ExcelFacade::store(new PublicFeedbackSubmissionsExport($columns, $labels, $rows, $csv), $path, $export->disk, $csv ? Excel::CSV : Excel::XLSX);

            $export->forceFill(['status' => 'ready', 'path' => $path, 'filename' => $filename, 'row_count' => count($rows), 'completed_at' => now()])->save();

            $audit->execute(
                eventType: AuditEventType::ServiceFeedbackExported,
                actor: $requester,
                auditable: $export,
                organizationId: $export->organization_id,
                newValues: ['format' => $export->format, 'rows' => count($rows), 'filters' => $filters],
                reason: 'Public submissions report exported',
            );
        } catch (Throwable $exception) {
            $export->forceFill(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()])->save();

            throw $exception;
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->export->fresh()?->forceFill(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()])->save();
    }
}
