<?php

declare(strict_types=1);

namespace Modules\Transfer\Application\Queries;

use Modules\Transfer\Application\Services\TransferAnnouncementPresenter;
use Modules\Transfer\Contracts\Data\TransferAnnouncementStatus;
use Modules\Transfer\Contracts\TransferAnnouncementListings;
use Modules\Transfer\Domain\Models\TransferAnnouncement;

final class TransferAnnouncementListingsQuery implements TransferAnnouncementListings
{
    public function publishedCount(): int
    {
        return TransferAnnouncement::query()->where('status', TransferAnnouncementStatus::Published)->count();
    }

    public function openPublicSummaries(int $limit): array
    {
        return TransferAnnouncement::query()
            ->open()
            ->with(['organization:id,name_en,name_am', 'position:id,title_en,title_am'])
            ->orderBy('closing_date')
            ->limit($limit)
            ->get()
            ->map(fn (TransferAnnouncement $transfer): array => TransferAnnouncementPresenter::summary($transfer))
            ->values()
            ->all();
    }
}
