<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Modules\Feedback\Application\Services\PublicFeedbackRoutingService;
use Modules\Feedback\Domain\Models\PublicFeedbackAssignment;

/**
 * ADR 0033: a Tip-off or complaint whose handler let the level's deadline pass
 * goes one level up the ladder. A case already at the top level stays where
 * it is and keeps showing as overdue.
 */
class EscalateOverduePublicCases extends Command
{
    protected $signature = 'service-feedback:escalate-overdue';

    protected $description = 'Escalate public Tip-offs and complaints past their deadline to the next level';

    public function handle(PublicFeedbackRoutingService $routing): int
    {
        $escalated = 0;
        $atTop = 0;

        PublicFeedbackAssignment::query()->overdue()->with('submission')
            ->chunkById(200, function ($rows) use ($routing, &$escalated, &$atTop): void {
                foreach ($rows as $assignment) {
                    $submission = $assignment->submission;

                    if ($submission === null || ! in_array($submission->status, PublicFeedbackRoutingService::ESCALATABLE, true)) {
                        continue;
                    }

                    try {
                        $routing->escalate($submission, null);
                        $escalated++;
                    } catch (ValidationException) {
                        $atTop++;
                    }
                }
            });

        $this->info("Escalated {$escalated}; {$atTop} overdue with no next level.");

        return self::SUCCESS;
    }
}
