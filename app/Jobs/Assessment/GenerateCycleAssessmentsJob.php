<?php

declare(strict_types=1);

namespace App\Jobs\Assessment;

use App\Models\AssessmentCycle;
use App\Services\Assessment\Execution\AssessmentAssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Fans a cycle's eligible employees out into chunk jobs of 500
 * (docs/assessment-execution.md#generation). Unique per cycle, so repeated
 * clicks do not stack work; every chunk is idempotent, so a retry is safe.
 */
class GenerateCycleAssessmentsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public string $cycleId, public int $actorId) {}

    public function uniqueId(): string
    {
        return $this->cycleId;
    }

    public function handle(): void
    {
        $cycle = AssessmentCycle::query()->find($this->cycleId);
        if ($cycle === null) {
            return;
        }
        $cycle->update(['assignment_generation_status' => 'running']);
        DB::table('assessment_cycle_employee_eligibility')->where('assessment_cycle_id', $cycle->id)->where('eligibility_status', 'eligible')
            ->orderBy('id')->select('id')->chunk(AssessmentAssignmentService::CHUNK, function ($rows) use ($cycle): void {
                GenerateAssessmentChunkJob::dispatch($cycle->id, $rows->pluck('id')->all(), $this->actorId);
            });
        // Chunks finish on their own; the status records that fan-out completed.
        $cycle->update(['assignment_generation_status' => 'dispatched', 'assignments_generated_at' => now()]);
    }
}
