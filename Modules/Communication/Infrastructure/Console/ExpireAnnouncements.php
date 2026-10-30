<?php

declare(strict_types=1);

namespace Modules\Communication\Infrastructure\Console;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use Illuminate\Console\Command;
use Modules\Communication\Domain\Enums\AnnouncementStatus;
use Modules\Communication\Domain\Models\Announcement;

/**
 * Marks published announcements past their expiry as expired. Readers stop
 * seeing them as soon as the time passes (the feed checks expires_at); this
 * records the change and keeps statuses honest for reports.
 */
class ExpireAnnouncements extends Command
{
    protected $signature = 'communication:expire-announcements';

    protected $description = 'Mark published Communication announcements past their expiry as expired';

    public function handle(WriteAuditLogAction $audit): int
    {
        $count = 0;
        Announcement::query()->where('status', AnnouncementStatus::Published->value)->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->orderBy('expires_at')->chunkById(200, function ($announcements) use ($audit, &$count): void {
                foreach ($announcements as $announcement) {
                    $announcement->forceFill(['status' => AnnouncementStatus::Expired])->save();
                    $audit->execute(AuditEventType::CommunicationAnnouncementExpired, null, $announcement, $announcement->organization_id,
                        ['status' => 'PUBLISHED'], ['status' => 'EXPIRED']);
                    $count++;
                }
            });

        $this->info("Expired {$count} announcement(s).");

        return self::SUCCESS;
    }
}
