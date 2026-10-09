<?php

declare(strict_types=1);

namespace App\Jobs\Assessment;

use App\Models\AssessmentCycle;
use App\Models\User;
use App\Services\Assessment\Execution\AssessmentAssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Creates the records and resolvable evaluator assignments for up to 500
 * eligible employees, then notifies the new evaluators in one batch.
 * Idempotent (unique record per employee/type/period, unique evaluator per
 * record): a retry only fills what is missing.
 */
class GenerateAssessmentChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /** @param array<int, string> $eligibilityIds */
    public function __construct(public string $cycleId, public array $eligibilityIds, public int $actorId) {}

    public function handle(AssessmentAssignmentService $assignments): void
    {
        $cycle = AssessmentCycle::query()->find($this->cycleId);
        $actor = User::query()->find($this->actorId);
        if ($cycle === null || $actor === null) {
            return;
        }
        $created = $assignments->generateChunk($cycle, $this->eligibilityIds, $actor);
        $assignments->notify($created);
    }
}
