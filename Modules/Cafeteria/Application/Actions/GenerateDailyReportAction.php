<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Cafeteria\Application\Services\CafeteriaReportService;
use Modules\Cafeteria\Domain\Enums\CafeteriaReportType;
use Modules\Cafeteria\Domain\Models\CafeteriaReportRun;

readonly class GenerateDailyReportAction
{
    public function __construct(
        private CafeteriaReportService $reportService,
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(Carbon $date, User $actor, ?string $organizationId = null, ?Request $request = null): CafeteriaReportRun
    {
        $report = $this->reportService->createReportRun(
            CafeteriaReportType::Daily,
            $date->copy()->startOfDay(),
            $date->copy()->endOfDay(),
            $actor,
            $organizationId,
        );

        $this->writeAuditLogAction->execute(
            AuditEventType::CafeteriaReportGenerated,
            $actor,
            $report,
            $organizationId,
            newValues: ['report_number' => $report->report_number, 'period' => $date->toDateString()],
            request: $request,
        );

        return $report;
    }
}
