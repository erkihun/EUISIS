<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

use App\Enums\ReportingBasis;
use App\Enums\ReportingOutcome;
use Closure;

/**
 * One organization's positions and units on one date, and the rule that puts
 * each position under another (ADR 0025).
 *
 * Precedence, per position:
 *   1. the published profile in force says "reports to" (explicit);
 *   2. a member of a unit reports to that unit's head position;
 *   3. a unit head reports to the parent unit's head, or — for a top-level
 *      unit — to the organization's head position;
 *   4. organization-level staff report to the organization's head;
 *   5. the organization's head reports to the parent organization's head in
 *      the published hierarchy, and is the top when there is no parent.
 *
 * Positions and units are loaded once per organization and date (hundreds
 * to a few thousand rows), never the workforce. People are resolved by
 * {@see OccupantResolver}.
 */
final class PositionReportingGraph
{
    /** @var array<string, list<string>>|null supervisor position id => subordinate position ids */
    private ?array $subordinates = null;

    /** @var array<string, PositionLink> */
    private array $links = [];

    private ?ParentOrganizationLink $parent = null;

    /**
     * @param  array<string, PositionNode>  $positions  keyed by id
     * @param  array<string, UnitNode>  $units  keyed by id
     * @param  Closure(): ParentOrganizationLink  $parentOrganization  loaded only when an organization head asks
     */
    public function __construct(
        public readonly string $organizationId,
        public readonly string $asOf,
        private readonly array $positions,
        private readonly array $units,
        private readonly Closure $parentOrganization,
    ) {}

    public function position(string $positionId): ?PositionNode
    {
        return $this->positions[$positionId] ?? null;
    }

    public function unit(string $unitId): ?UnitNode
    {
        return $this->units[$unitId] ?? null;
    }

    /** @return array<string, PositionNode> */
    public function positions(): array
    {
        return $this->positions;
    }

    /** @return array<string, UnitNode> */
    public function units(): array
    {
        return $this->units;
    }

    /**
     * The head position of a unit. More than one marked head is a structural
     * fault (legacy data before the uniqueness index): reported, not picked.
     *
     * @return array{0: ?PositionNode, 1: bool} the head, and whether several were marked
     */
    public function headOfUnit(string $unitId): array
    {
        $heads = array_values(array_filter($this->positions, fn (PositionNode $p): bool => $p->unitId === $unitId && $p->isUnitHead));

        return [count($heads) === 1 ? $heads[0] : null, count($heads) > 1];
    }

    /** @return array{0: ?PositionNode, 1: bool} the organization's head, and whether several were marked */
    public function organizationHead(): array
    {
        $heads = array_values(array_filter($this->positions, fn (PositionNode $p): bool => $p->isOrganizationHead()));

        return [count($heads) === 1 ? $heads[0] : null, count($heads) > 1];
    }

    public function parentOrganization(): ParentOrganizationLink
    {
        return $this->parent ??= ($this->parentOrganization)();
    }

    /** The position directly above the given one, by the rules in the class comment. */
    public function supervisorOf(string $positionId): PositionLink
    {
        return $this->links[$positionId] ??= $this->resolveLink($positionId);
    }

    /**
     * Positions whose supervising position is the given one (explicit lines
     * included, inferred lines only where the inference is unambiguous).
     *
     * @return list<string>
     */
    public function subordinatesOf(string $positionId): array
    {
        if ($this->subordinates === null) {
            $map = [];
            foreach ($this->positions as $node) {
                $link = $this->supervisorOf($node->id);
                if ($link->isResolved()) {
                    $map[$link->supervisorPositionId][] = $node->id;
                }
            }
            $this->subordinates = $map;
        }

        return $this->subordinates[$positionId] ?? [];
    }

    private function resolveLink(string $positionId): PositionLink
    {
        $node = $this->positions[$positionId] ?? null;
        if ($node === null) {
            return PositionLink::problem(ReportingOutcome::NoPosition, 'reporting-lines.explanations.position_missing');
        }

        if ($node->explicitReportsTo !== null) {
            return $this->explicitLink($node);
        }

        if ($node->unitId !== null) {
            return $this->unitLink($node);
        }

        if ($node->isOrganizationHead()) {
            return $this->organizationHeadLink();
        }

        return $this->organizationLevelStaffLink($node);
    }

    private function explicitLink(PositionNode $node): PositionLink
    {
        $targetId = (string) $node->explicitReportsTo;
        if ($targetId === $node->id) {
            return PositionLink::problem(ReportingOutcome::SelfReporting, 'reporting-lines.explanations.explicit_self', ['position' => $node->titleEn], ReportingBasis::ExplicitProfile);
        }
        $target = $this->positions[$targetId] ?? null;
        if ($target === null) {
            return PositionLink::problem(ReportingOutcome::ExplicitTargetInvalid, 'reporting-lines.explanations.explicit_target_missing', ['position' => $node->titleEn], ReportingBasis::ExplicitProfile);
        }
        if (! $target->isActiveOnDate) {
            return PositionLink::problem(ReportingOutcome::ExplicitTargetInvalid, 'reporting-lines.explanations.explicit_target_inactive', ['position' => $node->titleEn, 'target' => $target->titleEn], ReportingBasis::ExplicitProfile, $target->id);
        }

        return PositionLink::resolved(ReportingBasis::ExplicitProfile, $target->id, 'reporting-lines.explanations.explicit', ['position' => $node->titleEn, 'target' => $target->titleEn]);
    }

    private function unitLink(PositionNode $node): PositionLink
    {
        $unit = $this->units[(string) $node->unitId] ?? null;
        if ($unit === null || ! $unit->isActive) {
            return PositionLink::problem(ReportingOutcome::UnitInactive, 'reporting-lines.explanations.unit_inactive', ['unit' => $unit?->nameEn ?? (string) $node->unitId]);
        }

        if (! $node->isUnitHead) {
            [$head, $several] = $this->headOfUnit($unit->id);
            if ($several) {
                return PositionLink::problem(ReportingOutcome::InvalidHierarchy, 'reporting-lines.explanations.unit_several_heads', ['unit' => $unit->nameEn]);
            }
            if ($head === null) {
                return PositionLink::problem(ReportingOutcome::UnitHeadNotConfigured, 'reporting-lines.explanations.unit_head_missing', ['unit' => $unit->nameEn]);
            }
            if ($head->id === $node->id) {
                // Unreachable while isUnitHead is honoured above; kept as a defensive read of legacy data.
                return PositionLink::problem(ReportingOutcome::SelfReporting, 'reporting-lines.explanations.explicit_self', ['position' => $node->titleEn]);
            }
            if (! $head->isActiveOnDate) {
                return PositionLink::problem(ReportingOutcome::SupervisorPositionInactive, 'reporting-lines.explanations.head_inactive', ['unit' => $unit->nameEn, 'position' => $head->titleEn], ReportingBasis::UnitHead, $head->id);
            }

            return PositionLink::resolved(ReportingBasis::UnitHead, $head->id, 'reporting-lines.explanations.unit_head', ['unit' => $unit->nameEn, 'position' => $head->titleEn]);
        }

        // A unit head: up to the parent unit's head, or to the organization's head at the top.
        if ($unit->parentId !== null) {
            $parent = $this->units[$unit->parentId] ?? null;
            if ($parent === null || ! $parent->isActive) {
                return PositionLink::problem(ReportingOutcome::UnitInactive, 'reporting-lines.explanations.parent_unit_inactive', ['unit' => $unit->nameEn, 'parent' => $parent?->nameEn ?? $unit->parentId]);
            }
            [$head, $several] = $this->headOfUnit($parent->id);
            if ($several) {
                return PositionLink::problem(ReportingOutcome::InvalidHierarchy, 'reporting-lines.explanations.unit_several_heads', ['unit' => $parent->nameEn]);
            }
            if ($head === null) {
                return PositionLink::problem(ReportingOutcome::UnitHeadNotConfigured, 'reporting-lines.explanations.parent_unit_head_missing', ['unit' => $unit->nameEn, 'parent' => $parent->nameEn]);
            }
            if (! $head->isActiveOnDate) {
                return PositionLink::problem(ReportingOutcome::SupervisorPositionInactive, 'reporting-lines.explanations.head_inactive', ['unit' => $parent->nameEn, 'position' => $head->titleEn], ReportingBasis::ParentUnitHead, $head->id);
            }

            return PositionLink::resolved(ReportingBasis::ParentUnitHead, $head->id, 'reporting-lines.explanations.parent_unit_head', ['unit' => $unit->nameEn, 'parent' => $parent->nameEn, 'position' => $head->titleEn]);
        }

        return $this->organizationHeadFor($node, ReportingBasis::OrganizationHead, 'reporting-lines.explanations.top_unit_head', ['unit' => $unit->nameEn]);
    }

    private function organizationLevelStaffLink(PositionNode $node): PositionLink
    {
        return $this->organizationHeadFor($node, ReportingBasis::OrganizationHead, 'reporting-lines.explanations.organization_staff', ['position' => $node->titleEn]);
    }

    /** @param array<string, string|int|null> $params */
    private function organizationHeadFor(PositionNode $node, ReportingBasis $basis, string $explanation, array $params): PositionLink
    {
        [$head, $several] = $this->organizationHead();
        if ($several) {
            return PositionLink::problem(ReportingOutcome::InvalidHierarchy, 'reporting-lines.explanations.organization_several_heads');
        }
        if ($head === null) {
            return PositionLink::problem(ReportingOutcome::OrganizationHeadNotConfigured, 'reporting-lines.explanations.organization_head_missing', $params);
        }
        if ($head->id === $node->id) {
            return PositionLink::problem(ReportingOutcome::SelfReporting, 'reporting-lines.explanations.explicit_self', ['position' => $node->titleEn]);
        }
        if (! $head->isActiveOnDate) {
            return PositionLink::problem(ReportingOutcome::SupervisorPositionInactive, 'reporting-lines.explanations.organization_head_inactive', ['position' => $head->titleEn], $basis, $head->id);
        }

        return PositionLink::resolved($basis, $head->id, $explanation, $params + ['position' => $params['position'] ?? $head->titleEn, 'head' => $head->titleEn]);
    }

    private function organizationHeadLink(): PositionLink
    {
        $parent = $this->parentOrganization();
        if ($parent->ambiguous) {
            return PositionLink::problem(ReportingOutcome::InvalidHierarchy, 'reporting-lines.explanations.parent_organization_ambiguous');
        }
        if (! $parent->exists) {
            return PositionLink::top('reporting-lines.explanations.top_of_hierarchy');
        }
        if ($parent->headAmbiguous) {
            return PositionLink::problem(ReportingOutcome::InvalidHierarchy, 'reporting-lines.explanations.organization_several_heads_named', ['organization' => (string) $parent->organizationNameEn]);
        }
        if ($parent->headPosition === null) {
            return PositionLink::problem(ReportingOutcome::OrganizationHeadNotConfigured, 'reporting-lines.explanations.parent_organization_head_missing', ['organization' => (string) $parent->organizationNameEn]);
        }
        if (! $parent->headPosition->isActiveOnDate) {
            return PositionLink::problem(ReportingOutcome::SupervisorPositionInactive, 'reporting-lines.explanations.organization_head_inactive', ['position' => $parent->headPosition->titleEn], ReportingBasis::ParentOrganizationHead, $parent->headPosition->id);
        }

        return PositionLink::resolved(ReportingBasis::ParentOrganizationHead, $parent->headPosition->id, 'reporting-lines.explanations.parent_organization_head', ['organization' => (string) $parent->organizationNameEn, 'position' => $parent->headPosition->titleEn]);
    }
}
