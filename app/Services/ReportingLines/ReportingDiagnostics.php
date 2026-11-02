<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

use App\Enums\AssignmentStatus;
use App\Enums\OccupantBasis;
use App\Enums\ReportingOutcome;
use App\Enums\TemporaryAuthorityStatus;
use App\Models\ActingAppointment;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Support\Database\HeadPositionIndexes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only integrity report of one organization's reporting lines
 * (ADR 0025): what is missing, vacant, doubled, looping or inconsistent.
 * It changes nothing and guesses nothing; every finding names the unit or
 * position an administrator has to look at.
 */
class ReportingDiagnostics
{
    public function __construct(
        private readonly ReportingGraphRepository $graphs,
        private readonly OccupantResolver $occupants,
    ) {}

    /**
     * @return array{
     *   organization: array{id: string, name_en: string, name_am: ?string},
     *   as_of: string,
     *   summary: array{errors: int, warnings: int, units: int, units_with_head: int, positions: int},
     *   findings: list<array{code: string, severity: string, unit_id: ?string, unit_name_en: ?string, unit_name_am: ?string, position_id: ?string, position_title_en: ?string, position_title_am: ?string, detail: string, count: ?int}>,
     *   head_indexes: array<string, bool>
     * }
     */
    public function forOrganization(Organization $organization, Carbon|string|null $asOf = null): array
    {
        $day = ReportingGraphRepository::day($asOf);
        $organizationId = (string) $organization->getKey();
        $graph = $this->graphs->forOrganization($organizationId, $day);
        $findings = [];

        // Organization head.
        [$orgHead, $severalOrgHeads] = $graph->organizationHead();
        if ($severalOrgHeads) {
            $findings[] = $this->finding('organization_several_heads', 'error', detail: 'reporting-lines.explanations.organization_several_heads');
        } elseif ($orgHead === null) {
            $findings[] = $this->finding('organization_head_missing', 'warning', detail: 'reporting-lines.explanations.organization_head_missing');
        } else {
            $link = $graph->supervisorOf($orgHead->id);
            if ($link->outcome === ReportingOutcome::InvalidHierarchy) {
                $findings[] = $this->finding('parent_organization_ambiguous', 'error', position: $orgHead, detail: $link->explanation, params: $link->explanationParams);
            } elseif ($link->outcome === ReportingOutcome::OrganizationHeadNotConfigured) {
                $findings[] = $this->finding('parent_organization_head_missing', 'warning', position: $orgHead, detail: $link->explanation, params: $link->explanationParams);
            }
        }

        // Units and their heads.
        $headPositionIds = [];
        $unitsWithHead = 0;
        foreach ($graph->units() as $unit) {
            if (! $unit->isActive) {
                continue;
            }
            [$head, $several] = $graph->headOfUnit($unit->id);
            if ($several) {
                $findings[] = $this->finding('unit_several_heads', 'error', unit: $unit, detail: 'reporting-lines.explanations.unit_several_heads', params: ['unit' => $unit->nameEn]);

                continue;
            }
            if ($head === null) {
                $findings[] = $this->finding('unit_head_missing', 'warning', unit: $unit, detail: 'reporting-lines.explanations.unit_head_missing', params: ['unit' => $unit->nameEn]);

                continue;
            }
            $unitsWithHead++;
            if (! $head->isActiveOnDate) {
                $findings[] = $this->finding('head_inactive', 'error', unit: $unit, position: $head, detail: 'reporting-lines.explanations.head_inactive', params: ['unit' => $unit->nameEn, 'position' => $head->titleEn]);
            }
            $headPositionIds[$head->id] = $unit;
        }
        if ($orgHead !== null && ! $severalOrgHeads) {
            $headPositionIds[$orgHead->id] = null;
            if (! $orgHead->isActiveOnDate) {
                $findings[] = $this->finding('head_inactive', 'error', position: $orgHead, detail: 'reporting-lines.explanations.organization_head_inactive', params: ['position' => $orgHead->titleEn]);
            }
        }

        // Who holds the heads.
        $occupants = $this->occupants->occupantsOf(array_keys($headPositionIds), $day);
        foreach ($headPositionIds as $positionId => $unit) {
            $position = $graph->position((string) $positionId);
            $holders = $occupants[(string) $positionId] ?? [];
            $acting = array_filter($holders, fn (Occupant $o): bool => $o->basis === OccupantBasis::Acting && $o->employeeActive);
            $substantive = array_filter($holders, fn (Occupant $o): bool => $o->basis === OccupantBasis::Substantive && $o->employeeActive);
            if (count($acting) > 1) {
                $findings[] = $this->finding('acting_overlap', 'error', unit: $unit, position: $position, detail: 'reporting-lines.explanations.acting_overlap', params: ['position' => $position?->titleEn, 'count' => count($acting)], count: count($acting));
            } elseif (count($acting) === 0 && count($substantive) > 1) {
                $findings[] = $this->finding('head_several_holders', 'error', unit: $unit, position: $position, detail: 'reporting-lines.explanations.several_holders', params: ['position' => $position?->titleEn, 'count' => count($substantive)], count: count($substantive));
            } elseif (count($acting) === 0 && count($substantive) === 0 && $position?->isActiveOnDate) {
                $findings[] = $this->finding('head_vacant', 'warning', unit: $unit, position: $position, detail: count($holders) > 0 ? 'reporting-lines.explanations.holder_inactive' : 'reporting-lines.explanations.vacant', params: ['position' => $position?->titleEn]);
            }
        }

        // Explicit lines and loops, over every position.
        foreach ($graph->positions() as $node) {
            if (! $node->isActiveOnDate) {
                continue;
            }
            $link = $graph->supervisorOf($node->id);
            if (in_array($link->outcome, [ReportingOutcome::ExplicitTargetInvalid, ReportingOutcome::SelfReporting], true)) {
                $findings[] = $this->finding('explicit_target_invalid', 'error', unit: $node->unitId !== null ? $graph->unit($node->unitId) : null, position: $node, detail: $link->explanation, params: $link->explanationParams);
            }
            if ($this->loops($graph, $node->id)) {
                $findings[] = $this->finding('cycle', 'error', unit: $node->unitId !== null ? $graph->unit($node->unitId) : null, position: $node, detail: 'reporting-lines.explanations.cycle', params: ['position' => $node->titleEn]);
            }
        }

        // Overlapping acting appointments on any position (not only heads).
        $overlaps = $this->actingOverlaps($organizationId, $day);
        foreach ($overlaps as $positionId => $count) {
            if (isset($headPositionIds[$positionId])) {
                continue; // already reported above
            }
            $position = $graph->position($positionId);
            $findings[] = $this->finding('acting_overlap', 'error', position: $position, detail: 'reporting-lines.explanations.acting_overlap', params: ['position' => $position?->titleEn, 'count' => $count], count: $count);
        }

        // Assignment data that keeps people out of the structure.
        $withoutPosition = EmployeeAssignment::query()
            ->where('organization_id', $organizationId)
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active->value)
            ->whereNull('position_id')
            ->count();
        if ($withoutPosition > 0) {
            $findings[] = $this->finding('employees_without_position', 'warning', detail: 'reporting-lines.explanations.no_position', count: $withoutPosition);
        }
        $multiCurrent = EmployeeAssignment::query()
            ->select('employee_id')
            ->where('organization_id', $organizationId)
            ->where('is_current', true)
            ->where('assignment_status', AssignmentStatus::Active->value)
            ->groupBy('employee_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
        if ($multiCurrent > 0) {
            $findings[] = $this->finding('multiple_current_assignments', 'warning', detail: 'reporting-lines.explanations.ambiguous_assignment', params: ['count' => 2], count: $multiCurrent);
        }

        $indexes = HeadPositionIndexes::status();
        foreach ($indexes as $name => $present) {
            if (! $present) {
                $findings[] = $this->finding('head_index_missing', 'warning', detail: 'reporting-lines.diagnostics.head_index_missing', params: [], count: null, extra: $name);
            }
        }

        $errors = count(array_filter($findings, fn (array $f): bool => $f['severity'] === 'error'));

        return [
            'organization' => ['id' => $organizationId, 'name_en' => (string) $organization->name_en, 'name_am' => $organization->name_am],
            'as_of' => $day,
            'summary' => [
                'errors' => $errors,
                'warnings' => count($findings) - $errors,
                'units' => count(array_filter($graph->units(), fn (UnitNode $u): bool => $u->isActive)),
                'units_with_head' => $unitsWithHead,
                'positions' => count(array_filter($graph->positions(), fn (PositionNode $p): bool => $p->isActiveOnDate)),
            ],
            'findings' => $findings,
            'head_indexes' => $indexes,
        ];
    }

    /** Positions whose active acting appointments overlap on the day, as position id => count. */
    private function actingOverlaps(string $organizationId, string $day): array
    {
        return ActingAppointment::query()
            ->select('position_id', DB::raw('COUNT(*) as n'))
            ->where('organization_id', $organizationId)
            ->where('status', TemporaryAuthorityStatus::Active->value)
            ->whereDate('starts_on', '<=', $day)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $day))
            ->groupBy('position_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->position_id => (int) $row->n])
            ->all();
    }

    private function loops(PositionReportingGraph $graph, string $positionId): bool
    {
        $visited = [];
        $current = $positionId;
        for ($depth = 0; $depth < ReportingLineResolver::MAX_DEPTH; $depth++) {
            if (isset($visited[$current])) {
                return true;
            }
            $visited[$current] = true;
            $link = $graph->supervisorOf($current);
            if (! $link->isResolved() || $graph->position((string) $link->supervisorPositionId) === null) {
                return false;
            }
            $current = (string) $link->supervisorPositionId;
        }

        return false;
    }

    /** @param array<string, string|int|null> $params */
    private function finding(string $code, string $severity, ?UnitNode $unit = null, ?PositionNode $position = null, string $detail = '', array $params = [], ?int $count = null, ?string $extra = null): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'title' => (string) __('reporting-lines.diagnostics.'.$code),
            'unit_id' => $unit?->id,
            'unit_name_en' => $unit?->nameEn,
            'unit_name_am' => $unit?->nameAm,
            'position_id' => $position?->id,
            'position_title_en' => $position?->titleEn,
            'position_title_am' => $position?->titleAm,
            'detail' => $detail === '' ? '' : (string) __($detail, $params),
            'count' => $count,
            'extra' => $extra,
        ];
    }
}
