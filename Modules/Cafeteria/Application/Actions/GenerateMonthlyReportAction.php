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

readonly class GenerateMonthlyReportAction
{
    public function __construct(
        private CafeteriaReportService $reportService,
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(int $year, int $month, User $actor, ?string $organizationId = null, ?Request $request = null): CafeteriaReportRun
    {
        $from = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $report = $this->reportService->createReportRun(
            CafeteriaReportType::Monthly,
            $from,
            $to,
            $actor,
            $organizationId,
        );

        $this->writeAuditLogAction->execute(
            AuditEventType::CafeteriaReportGenerated,
            $actor,
            $report,
            $organizationId,
            newValues: [
                'report_number' => $report->report_number,
                'period' => $from->format('Y-m'),
            ],
            request: $request,
        );

        return $report;
    }
}
