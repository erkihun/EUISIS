<?php

declare(strict_types=1);

use App\Jobs\Assessment\GenerateAssessmentChunkJob;
use App\Jobs\Assessment\GenerateCycleAssessmentsJob;
use App\Jobs\EscalateOverdueGrievancesJob;
use App\Jobs\Performance\RecalculatePlanScore;
use App\Jobs\Transfers\NotifyEligibleTransferAudience;

/*
 * Production risk register INF-2/INF-3: a job still running when the
 * connection's retry_after elapses is handed to a second worker and runs
 * twice. Long jobs must finish inside retry_after and run on their own
 * `long` queue so they never starve operational work on `default`.
 */
dataset('long jobs', fn () => [
    'grievance escalation' => [new EscalateOverdueGrievancesJob],
    'plan score' => [new RecalculatePlanScore('plan', '2026-01-01')],
    'cycle assessments' => [new GenerateCycleAssessmentsJob('cycle', 1)],
    'assessment chunk' => [new GenerateAssessmentChunkJob('cycle', [], 1)],
    'transfer audience' => [new NotifyEligibleTransferAudience('announcement')],
]);

it('runs long jobs on the dedicated long queue', function (object $job): void {
    expect($job->queue)->toBe('long');
})->with('long jobs');

it('keeps every long job inside the queue retry window', function (object $job): void {
    foreach (['database', 'redis'] as $connection) {
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after");
        expect($job->timeout ?? 60)->toBeLessThan($retryAfter);
    }
})->with('long jobs');
