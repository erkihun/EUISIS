<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Queries;

use Modules\Feedback\Contracts\FeedbackReportExports;
use Modules\Feedback\Domain\Models\PublicFeedbackReportExport;

final class FeedbackReportExportCommands implements FeedbackReportExports
{
    public function queue(string $report, string $format, array $filters, int|string $requestedBy, ?string $organizationId): string
    {
        return (string) PublicFeedbackReportExport::query()->create([
            'organization_id' => $organizationId,
            'report' => $report,
            'format' => $format,
            'filters' => $filters,
            'status' => 'queued',
            'disk' => 'local',
            'requested_by' => $requestedBy,
        ])->getKey();
    }

    public function markReady(string $exportId, string $path, string $filename, int $rowCount): void
    {
        PublicFeedbackReportExport::query()->findOrFail($exportId)
            ->forceFill(['status' => 'ready', 'path' => $path, 'filename' => $filename, 'row_count' => $rowCount, 'completed_at' => now()])
            ->save();
    }

    public function markFailed(string $exportId, string $error): void
    {
        PublicFeedbackReportExport::query()->find($exportId)
            ?->forceFill(['status' => 'failed', 'error' => mb_substr($error, 0, 2000), 'completed_at' => now()])
            ->save();
    }
}
