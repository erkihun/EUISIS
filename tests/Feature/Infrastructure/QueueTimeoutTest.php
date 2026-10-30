<?php

declare(strict_types=1);

use App\Jobs\Performance\RecalculatePlanScore;
use App\Jobs\Transfers\NotifyEligibleTransferAudience;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Assessment\Infrastructure\Jobs\GenerateAssessmentChunkJob;
use Modules\Assessment\Infrastructure\Jobs\GenerateCycleAssessmentsJob;
use Modules\Grievance\Infrastructure\Jobs\EscalateOverdueGrievancesJob;

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

it('keeps every queued job inside the queue retry window, on any queue', function (): void {
    // Announcement delivery (900s, on `notifications`) once equalled the 900s
    // default and would have run twice; read every job, not only the long ones.
    $timeouts = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Jobs'), FilesystemIterator::SKIP_DOTS)) as $file) {
        $class = 'App\\Jobs\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen(app_path('Jobs')) + 1));
        if (class_exists($class) && is_subclass_of($class, ShouldQueue::class)) {
            $timeouts[$class] = (int) ((new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? 60);
        }
    }

    expect($timeouts)->not->toBeEmpty();
    foreach (['database', 'redis'] as $connection) {
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after");
        foreach ($timeouts as $class => $timeout) {
            expect($timeout)->toBeLessThan($retryAfter, "{$class} ({$timeout}s) must finish inside {$connection} retry_after ({$retryAfter}s).");
        }
    }
});
