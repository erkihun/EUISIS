<?php

declare(strict_types=1);

namespace Modules\Grievance\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Grievance\Application\Services\GrievanceCaseService;
use Modules\Grievance\Application\Services\GrievanceEscalationService;
use Modules\Grievance\Application\Services\GrievanceSettings;

/**
 * SLA reminders, automatic escalation of overdue stages and (when enabled)
 * closure after the appeal window. Scheduled; never triggered by page visits.
 * Safe to run twice: escalation is idempotent at the database level.
 */
class ProcessGrievanceSla extends Command
{
    protected $signature = 'grievances:process-sla';

    protected $description = 'Send grievance SLA reminders, auto-escalate overdue stages and close expired appeal windows';

    public function handle(GrievanceEscalationService $escalation, GrievanceCaseService $cases, GrievanceSettings $settings): int
    {
        $stats = $escalation->processDueStages();
        $closed = $settings->autoCloseAfterAppealWindow() ? $escalation->closeExpiredAppealWindows($cases) : 0;

        $this->info(sprintf('Warnings: %d · escalated: %d · blocked (no route): %d · closed after appeal window: %d', $stats['warned'], $stats['escalated'], $stats['blocked'], $closed));

        return self::SUCCESS;
    }
}
