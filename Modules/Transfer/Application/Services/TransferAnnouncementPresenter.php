<?php

declare(strict_types=1);

namespace Modules\Transfer\Application\Services;

use Modules\Transfer\Domain\Models\TransferAnnouncement;

/**
 * The public summary of a transfer announcement. The public pages, My Portal
 * and the combined announcements page show the same fields and nothing more.
 */
final class TransferAnnouncementPresenter
{
    /** @return array<string, mixed> */
    public static function summary(TransferAnnouncement $a): array
    {
        return [
            'id' => $a->id,
            'organization_name_en' => $a->organization?->name_en,
            'organization_name_am' => $a->organization?->name_am,
            'position_title_en' => $a->position?->title_en,
            'position_title_am' => $a->position?->title_am,
            'grade_level' => $a->grade_level,
            'number_of_vacancies' => $a->totalVacancyCount(),
            'opening_date' => $a->opening_date?->toDateString(),
            'closing_date' => $a->closing_date?->toDateString(),
            'published_at' => $a->published_at?->toDateString(),
            'is_open' => $a->isAcceptingApplications(),
        ];
    }
}
