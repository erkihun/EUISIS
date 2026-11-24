<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Documents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Reporting\Application\Ports\ArtifactQueue;
use Modules\Reporting\Infrastructure\Jobs\RenderArtifact;
use Modules\Reporting\Infrastructure\Persistence\Models\Artifact;
use Throwable;

/**
 * The artifact row is the request. It is written in the caller's transaction
 * (so a submission and its request for a Word file commit together); the job
 * is dispatched after the commit, and the scheduled task re-dispatches rows
 * whose job never ran. One row per owner, format and label: asking twice
 * queues once.
 */
final readonly class DatabaseArtifactQueue implements ArtifactQueue
{
    public function request(string $ownerType, string $ownerId, string $label, string $format = 'docx'): string
    {
        $artifact = Artifact::query()->firstOrCreate(
            ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'format' => $format, 'label' => $label],
            ['status' => 'queued'],
        );
        if (! $artifact->wasRecentlyCreated) {
            // A regeneration (a consolidation compiled again, a failed file retried).
            $artifact->forceFill(['status' => 'queued', 'attempts' => 0, 'error' => null])->save();
        }
        $id = $artifact->id;
        DB::afterCommit(static function () use ($id): void {
            try {
                RenderArtifact::dispatch($id);
            } catch (Throwable $e) {
                // With a synchronous queue the job runs here: its failure is on the artifact row
                // and must not fail the request whose transaction has already committed.
                Log::warning('reporting: artifact generation failed', ['artifact' => $id, 'error' => $e->getMessage()]);
            }
        });

        return $id;
    }
}
