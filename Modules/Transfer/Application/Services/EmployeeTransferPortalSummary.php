<?php

declare(strict_types=1);

namespace Modules\Transfer\Application\Services;

use Modules\Transfer\Contracts\Data\TransferAnnouncementStatus;
use Modules\Transfer\Contracts\Data\TransferApplicationStatus;
use Modules\Transfer\Contracts\EmployeeTransferActivity;
use Modules\Transfer\Domain\Models\TransferAnnouncement;
use Modules\Transfer\Domain\Models\TransferApplication;

/** My Portal's transfer card: the employee's latest applications and the open announcements they have not applied to. */
final class EmployeeTransferPortalSummary implements EmployeeTransferActivity
{
    /**
     * @return array{applications: list<array<string, mixed>>, open_announcements: list<array<string, mixed>>}
     */
    public function forEmployee(string $employeeId): array
    {
        // ── Transfer applications ─────────────────────────────────────────────
        $appliedIds = TransferApplication::query()
            ->where('employee_id', $employeeId)
            ->whereNotIn('status', [
                TransferApplicationStatus::Withdrawn->value,
                TransferApplicationStatus::Cancelled->value,
            ])
            ->pluck('announcement_id')
            ->all();

        $transferApps = TransferApplication::query()
            ->where('employee_id', $employeeId)
            ->with(['announcement.organization', 'announcement.position'])
            ->orderByDesc('submitted_at')
            ->limit(5)
            ->get()
            ->map(fn (TransferApplication $app) => [
                'id' => $app->id,
                'status' => $app->status?->value,
                'status_label' => $app->status?->label(),
                'submitted_at' => $app->submitted_at?->toDateString(),
                'organization' => $app->announcement?->organization?->name_en,
                'organization_am' => $app->announcement?->organization?->name_am,
                'position' => $app->announcement?->position?->title_en,
                'position_am' => $app->announcement?->position?->title_am,
                'announcement_id' => $app->announcement_id,
                'closing_date' => $app->announcement?->closing_date?->toDateString(),
            ])
            ->all();

        // ── Open announcements ────────────────────────────────────────────────
        $openAnnouncements = TransferAnnouncement::query()
            ->where('status', TransferAnnouncementStatus::Published)
            ->where('opening_date', '<=', now())
            ->where('closing_date', '>=', now())
            ->with(['organization', 'position'])
            ->when(count($appliedIds) > 0, fn ($q) => $q->whereNotIn('id', $appliedIds))
            ->orderByDesc('published_at')
            ->limit(3)
            ->get()
            ->map(fn (TransferAnnouncement $a) => [
                'id' => $a->id,
                'organization' => $a->organization?->name_en,
                'organization_am' => $a->organization?->name_am,
                'position' => $a->position?->title_en,
                'position_am' => $a->position?->title_am,
                'grade_level' => $a->grade_level,
                'vacancies' => $a->totalVacancyCount(),
                'closing_date' => $a->closing_date?->toDateString(),
            ])
            ->all();

        return ['applications' => $transferApps, 'open_announcements' => $openAnnouncements];
    }
}
