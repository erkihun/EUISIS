<?php

declare(strict_types=1);

namespace Modules\Transfer\Contracts;

/**
 * Published transfer announcements as the rest of the application lists them
 * (ADR 0017): the navigation badge and the public announcements page. The
 * announcement pages themselves are the Transfer module's.
 */
interface TransferAnnouncementListings
{
    /** How many announcements are published (open or not yet closed by status). */
    public function publishedCount(): int;

    /**
     * Announcements open for applications today, soonest closing first, in the
     * public summary shape the transfer pages use.
     *
     * @return list<array<string, mixed>>
     */
    public function openPublicSummaries(int $limit): array;
}
