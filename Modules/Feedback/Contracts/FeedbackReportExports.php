<?php

declare(strict_types=1);

namespace Modules\Feedback\Contracts;

/**
 * The owner command for report exports kept with the feedback exports
 * (ADR 0017). The Situation Room queues its institution exports here and
 * records how the build ended; it reads them back through
 * ReadModels\FeedbackReportExportRecord.
 */
interface FeedbackReportExports
{
    /**
     * Records a queued export of the named report and returns its id.
     *
     * @param  'csv'|'xlsx'  $format
     * @param  array<string, mixed>  $filters  as the requester chose them, with the locale
     */
    public function queue(string $report, string $format, array $filters, int|string $requestedBy, ?string $organizationId): string;

    /** The file is stored; the export becomes downloadable. */
    public function markReady(string $exportId, string $path, string $filename, int $rowCount): void;

    /** The build failed or was refused; the reason is kept (shortened to 2000 characters). */
    public function markFailed(string $exportId, string $error): void;
}
