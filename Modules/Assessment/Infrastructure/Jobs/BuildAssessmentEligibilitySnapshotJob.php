<?php

declare(strict_types=1);

namespace Modules\Assessment\Infrastructure\Jobs;

use Modules\Assessment\Domain\Models\AssessmentCycle;
use App\Models\User;
use Modules\Assessment\Application\Services\Oversight\AssessmentEligibilityService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds a cycle's eligibility snapshot in bounded steps (risk register
 * SCL-6): each run resolves a few thousand employees by key range, commits,
 * and queues the next run with its cursor. Resumable, always well inside the
 * queue timeout, and never one transaction over the whole workforce. The
 * snapshot is marked ready only by the final run.
 */
class BuildAssessmentEligibilitySnapshotJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    /** @param array{eligible: int, excluded: int} $counts */
    public function __construct(
        public string $cycleId,
        public int $actorId,
        public ?string $after = null,
        public array $counts = ['eligible' => 0, 'excluded' => 0],
    ) {
        $this->onQueue('long');
    }

    public function uniqueId(): string
    {
        return "eligibility-snapshot:{$this->cycleId}:".($this->after ?? 'start');
    }

    public function handle(AssessmentEligibilityService $eligibility): void
    {
        $cycle = AssessmentCycle::query()->find($this->cycleId);
        $actor = User::query()->find($this->actorId);
        if ($cycle === null || $actor === null || $cycle->isEligibilityFinalized()) {
            return;
        }

        [$next, $counts] = $eligibility->buildSnapshotBatch($cycle, $this->after, $this->counts);

        if ($next === null) {
            $eligibility->completeSnapshot($actor, $cycle, $counts);

            return;
        }

        self::dispatch($this->cycleId, $this->actorId, $next, $counts);
    }
}
