<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Communication\PublishAnnouncementAction;
use App\Enums\Communication\AnnouncementStatus;
use App\Models\Communication\Announcement;
use App\Models\User;
use Illuminate\Console\Command;

/** Puts scheduled announcements live once their time has come (every minute). */
class PublishScheduledAnnouncements extends Command
{
    protected $signature = 'communication:publish-scheduled';

    protected $description = 'Publish Communication announcements whose scheduled time has passed';

    public function handle(PublishAnnouncementAction $publish): int
    {
        $count = 0;
        Announcement::query()->where('status', AnnouncementStatus::Scheduled->value)->where('publish_at', '<=', now())
            ->orderBy('publish_at')->limit(200)->get()
            ->each(function (Announcement $announcement) use ($publish, &$count): void {
                // An announcement that would expire before it goes out is withdrawn from the schedule instead.
                if ($announcement->expires_at !== null && $announcement->expires_at->isPast()) {
                    $announcement->forceFill(['status' => AnnouncementStatus::Expired])->save();

                    return;
                }
                $publish->publishNow($announcement, $announcement->published_by ? User::query()->find($announcement->published_by) : null);
                $count++;
            });

        $this->info("Published {$count} scheduled announcement(s).");

        return self::SUCCESS;
    }
}
