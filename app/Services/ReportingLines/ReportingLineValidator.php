<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

use App\Models\Position;
use Illuminate\Validation\ValidationException;

/**
 * Refuses structure changes that would make a position its own superior,
 * directly or around a loop of explicit and inferred lines (ADR 0025).
 * Reads the live graph plus the proposed change, so a draft profile is
 * checked when written and again when it is published.
 */
class ReportingLineValidator
{
    public function __construct(private readonly ReportingGraphRepository $graphs) {}

    /**
     * An explicit "reports to" line for the position: the target must be a
     * live position of the same organization, not the position itself, and
     * following the lines up from the target must never return.
     */
    public function assertReportsTo(Position $position, ?string $reportsTo): void
    {
        if ($reportsTo === null || $reportsTo === '') {
            return;
        }
        if ($reportsTo === (string) $position->getKey()) {
            throw ValidationException::withMessages(['reports_to_position_id' => __('reporting-lines.validation.self')]);
        }
        $target = Position::query()->whereKey($reportsTo)->where('organization_id', $position->organization_id)->first(['id', 'is_active', 'effective_to']);
        if ($target === null) {
            throw ValidationException::withMessages(['reports_to_position_id' => __('organization-design.profile_reports_to_invalid')]);
        }
        if (! $target->is_active || ($target->effective_to !== null && $target->effective_to->endOfDay()->isPast())) {
            throw ValidationException::withMessages(['reports_to_position_id' => __('reporting-lines.validation.target_inactive')]);
        }

        $this->graphs->forget();
        $graph = $this->graphs->forOrganization((string) $position->organization_id);
        if ($this->reaches($graph, (string) $target->id, (string) $position->getKey())) {
            throw ValidationException::withMessages(['reports_to_position_id' => __('reporting-lines.validation.cycle')]);
        }
    }

    /**
     * Marking a position as a unit's head: the head's own line must not lead
     * back into the unit it leads (a published profile that reports to a
     * member of that unit would do that).
     */
    public function assertHeadHasNoCycle(Position $position, ?string $organizationUnitId): void
    {
        $this->graphs->forget();
        $graph = $this->graphs->forOrganization((string) $position->organization_id);
        $node = $graph->position((string) $position->getKey());
        if ($node === null || $node->explicitReportsTo === null) {
            return;
        }
        // The explicit line wins for the head itself; walking up from it must not come back.
        if ($this->reaches($graph, $node->explicitReportsTo, (string) $position->getKey(), [(string) $position->getKey()])) {
            throw ValidationException::withMessages(['is_unit_head' => __('reporting-lines.validation.head_cycle')]);
        }
        // And a member of the unit whose explicit line points at this head must not be the head's own superior.
        foreach ($graph->positions() as $candidate) {
            if ($candidate->unitId === $organizationUnitId && $candidate->id !== $node->id && $this->reaches($graph, $node->explicitReportsTo, $candidate->id)) {
                throw ValidationException::withMessages(['is_unit_head' => __('reporting-lines.validation.head_cycle')]);
            }
        }
    }

    /**
     * Whether following the supervising lines up from $from reaches $target
     * within the depth limit; the inferred line of $from is overridden when
     * $from itself is the position being changed.
     *
     * @param  list<string>  $pretendVisited
     */
    private function reaches(PositionReportingGraph $graph, string $from, string $target, array $pretendVisited = []): bool
    {
        $visited = array_fill_keys($pretendVisited, true);
        $current = $from;
        for ($depth = 0; $depth < ReportingLineResolver::MAX_DEPTH; $depth++) {
            if ($current === $target) {
                return true;
            }
            if (isset($visited[$current])) {
                return false; // an existing loop elsewhere: reported by diagnostics, not this change
            }
            $visited[$current] = true;
            $link = $graph->supervisorOf($current);
            if (! $link->isResolved() || $graph->position($link->supervisorPositionId) === null) {
                return false; // the top, a parent organization, or a problem that stops the walk
            }
            $current = (string) $link->supervisorPositionId;
        }

        return false;
    }
}
