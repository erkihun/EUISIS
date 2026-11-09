<?php

declare(strict_types=1);

namespace Modules\Communication\Infrastructure\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Communication\Application\Services\AnnouncementAudience;
use Modules\Communication\Domain\Models\Announcement;

/** Counts how many people an announcement's targets reach (shown to authors and in the report). */
class ComputeAnnouncementTargetCount implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 60;

    public function __construct(public string $announcementId) {}

    public function uniqueId(): string
    {
        return $this->announcementId;
    }

    public function handle(AnnouncementAudience $audience): void
    {
        $announcement = Announcement::query()->find($this->announcementId);
        if ($announcement === null) {
            return;
        }
        $announcement->forceFill([
            'target_count' => $audience->recipients($announcement)->count(),
            'target_count_computed_at' => now(),
        ])->saveQuietly();
    }
}
