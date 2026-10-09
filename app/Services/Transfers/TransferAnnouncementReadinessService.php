<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Enums\EstablishmentStatus;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\TransferAnnouncement;

/** Produces an explainable, non-mutating publish-readiness decision. */
readonly class TransferAnnouncementReadinessService
{
    public function assess(TransferAnnouncement $announcement): array
    {
        $issues = [];
        if ($announcement->organization_id === null) {
            $issues[] = $this->issue('ORGANIZATION_MISSING', 'Destination organization is missing.');
        }
        if ($announcement->opening_date === null || $announcement->closing_date === null || $announcement->closing_date->lte($announcement->opening_date)) {
            $issues[] = $this->issue('INVALID_DATES', 'Application dates are incomplete or invalid.');
        }

        foreach ((array) $announcement->eligibility_rules as $rule) {
            if (! is_array($rule)
                || ! in_array($rule['type'] ?? null, ['employment_status', 'current_grade', 'current_organization', 'current_position', 'minimum_service_months'], true)
                || ! in_array($rule['operator'] ?? null, ['equals', 'in', 'greater_than_or_equal'], true)
                || ! is_string($rule['value'] ?? null)) {
                $issues[] = $this->issue('INVALID_ELIGIBILITY_RULE', 'An eligibility rule is not in a supported, safe format.');
                break;
            }
        }

        $lines = $announcement->positions()->get();
        // Existing records created before announcement-position rows were
        // introduced retain their canonical single-position fields.
        if ($lines->isEmpty() && $announcement->position_id !== null) {
            $lines = collect([(object) [
                'id' => 'legacy-'.$announcement->id,
                'position_id' => $announcement->position_id,
                'organization_id' => $announcement->organization_id,
                'vacancy_count' => $announcement->number_of_vacancies,
            ]]);
        }
        if ($lines->isEmpty()) {
            $issues[] = $this->issue('NO_POSITIONS', 'No advertised positions are configured.');
        }

        $positions = [];
        foreach ($lines as $line) {
            $position = Position::query()->with('organizationUnit:id,name_en,name_am,code')->find($line->position_id);
            $available = PositionEstablishment::query()
                ->where('organization_id', $line->organization_id)
                ->where('position_id', $line->position_id)
                ->where('status', EstablishmentStatus::Approved->value)
                ->get()
                ->sum(fn (PositionEstablishment $e): int => $e->availableSlots());
            if ($position !== null && $position->organization_id !== $line->organization_id) {
                $issues[] = $this->issue('ORGANIZATION_MISMATCH', 'An advertised position belongs to another organization.', $line->position_id);
            } elseif ($position === null || ! $position->isSelectable()) {
                $issues[] = $this->issue('POSITION_NOT_IN_STRUCTURE', 'An advertised position is inactive or no longer in the approved structure.', $line->position_id);
            }
            if ($available < 1) {
                $issues[] = $this->issue('NO_AVAILABLE_CAPACITY', 'An advertised position no longer has approved capacity.', $line->position_id);
            }
            if ($line->vacancy_count < 1 || $line->vacancy_count > $available) {
                $issues[] = $this->issue('ADVERTISED_SLOTS_EXCEED_CAPACITY', 'Advertised slots exceed current available capacity.', $line->position_id);
            }

            $positions[] = [
                'id' => $line->id,
                'position_id' => $line->position_id,
                'organization_id' => $line->organization_id,
                'advertised_slots' => $line->vacancy_count,
                'current_available' => $available,
                'position' => $position ? [
                    'code' => $position->job_position_code,
                    'title_en' => $position->title_en,
                    'title_am' => $position->title_am,
                    'grade_level' => $position->grade_level,
                    'organization_unit_id' => $position->organization_unit_id,
                    'organization_unit_name_en' => $position->organizationUnit?->name_en,
                    'organization_unit_name_am' => $position->organizationUnit?->name_am,
                ] : null,
            ];
        }

        return ['status' => $issues === [] ? 'ready' : 'not_ready', 'ready' => $issues === [], 'issues' => $issues, 'positions' => $positions];
    }

    private function issue(string $code, string $message, ?string $positionId = null): array
    {
        return array_filter(['code' => $code, 'message' => $message, 'position_id' => $positionId], fn ($value) => $value !== null);
    }
}
