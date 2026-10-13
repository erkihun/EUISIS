<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Models\OrganizationDesignStudy;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\UnitDesignProposal;
use App\Models\UnitResponsibility;

/**
 * Structure validation gates (brief §44, 79–81, 119). Every check yields a
 * finding with a severity (error / warning / info), a stable code, a
 * localized message and its subject. ERROR findings block approval;
 * WARNING and INFO are shown and never block. No thresholds are invented:
 * span of control is reported as information (NEEDS_DECISION for limits).
 */
class OrganizationDesignValidationService
{
    public function __construct(private readonly ResponsibilityService $responsibilities) {}

    /**
     * The organization's current unit tree.
     *
     * @return array{ok: bool, summary: array{errors: int, warnings: int, infos: int}, findings: list<array<string, mixed>>, generated_at: string}
     */
    public function validateStructure(string $organizationId): array
    {
        $findings = [];
        $units = OrganizationUnit::query()->where('organization_id', $organizationId)->orderBy('code')->get();
        $byId = $units->keyBy('id');
        $childrenOf = $units->groupBy(fn (OrganizationUnit $u) => $u->parent_unit_id ?? '');
        $positions = Position::query()->whereIn('organization_unit_id', $units->pluck('id'))->get(['id', 'organization_unit_id', 'is_active'])->groupBy('organization_unit_id');

        // Cycles: walk each unit's parent chain; a unit seen twice closes a cycle.
        $inCycle = [];
        foreach ($units as $unit) {
            $seen = [];
            for ($node = $unit; $node !== null; $node = $node->parent_unit_id === null ? null : $byId->get($node->parent_unit_id)) {
                if (isset($seen[$node->id])) {
                    foreach (array_keys($seen) as $id) {
                        $inCycle[$id] = true;
                    }
                    break;
                }
                $seen[$node->id] = true;
            }
        }
        foreach (array_keys($inCycle) as $id) {
            $findings[] = $this->finding('error', 'unit_cycle', $this->unitSubject($byId->get($id)));
        }

        $codes = $units->groupBy('code')->filter(fn ($g) => $g->count() > 1);
        foreach ($codes as $group) {
            $findings[] = $this->finding('error', 'unit_duplicate_code', $this->unitSubject($group->first()), ['count' => $group->count()]);
        }

        foreach ($units as $unit) {
            $status = $unit->status instanceof \BackedEnum ? $unit->status->value : (string) $unit->status;
            $active = $status === 'active';
            $parent = $unit->parent_unit_id === null ? null : $byId->get($unit->parent_unit_id);
            if ($unit->parent_unit_id !== null && $parent === null) {
                $foreign = OrganizationUnit::query()->whereKey($unit->parent_unit_id)->exists();
                $findings[] = $this->finding('error', $foreign ? 'unit_parent_foreign' : 'unit_parent_missing', $this->unitSubject($unit));
            }
            $parentStatus = $parent === null ? null : ($parent->status instanceof \BackedEnum ? $parent->status->value : (string) $parent->status);
            if ($active && $parent !== null && $parentStatus !== 'active' && ! isset($inCycle[$unit->id])) {
                $findings[] = $this->finding('error', 'active_unit_inactive_parent', $this->unitSubject($unit), ['parent' => $parent->code]);
            }
            $unitPositions = $positions->get($unit->id, collect());
            $activePositions = $unitPositions->where('is_active', true)->count();
            if (! $active && $activePositions > 0) {
                $findings[] = $this->finding('error', 'inactive_unit_active_positions', $this->unitSubject($unit), ['count' => $activePositions]);
            }
            if ($unit->organization_unit_type_id === null) {
                $findings[] = $this->finding('warning', 'unit_without_type', $this->unitSubject($unit));
            }
            if ($active && $unitPositions->isEmpty()) {
                $findings[] = $this->finding('warning', 'unit_without_positions', $this->unitSubject($unit));
            }
            $children = $childrenOf->get($unit->id, collect());
            if ($children->isNotEmpty()) {
                $findings[] = $this->finding('info', 'span_of_control', $this->unitSubject($unit), ['count' => $children->count()]);
            }
        }

        $roots = $childrenOf->get('', collect());
        $findings[] = $this->finding('info', 'root_units', ['type' => 'organization', 'id' => $organizationId, 'code' => null, 'name' => null], ['count' => $roots->count()]);

        $gaps = $this->responsibilities->gaps($organizationId);
        foreach ($gaps['functions'] as $f) {
            $findings[] = $this->finding('warning', 'function_uncovered', ['type' => 'function', 'id' => $f['id'], 'code' => $f['code'], 'name' => $f['name_en']]);
        }
        foreach ($gaps['services'] as $s) {
            $findings[] = $this->finding('warning', 'service_uncovered', ['type' => 'service', 'id' => $s['id'], 'code' => $s['code'], 'name' => $s['name_en']]);
        }
        foreach ($gaps['processes'] as $p) {
            $findings[] = $this->finding('warning', 'process_uncovered', ['type' => 'process', 'id' => $p['id'], 'code' => $p['code'], 'name' => $p['name_en']]);
        }

        return $this->report($findings);
    }

    /**
     * A study's proposals against the current structure.
     *
     * @return array{ok: bool, summary: array{errors: int, warnings: int, infos: int}, findings: list<array<string, mixed>>, generated_at: string}
     */
    public function validateStudy(OrganizationDesignStudy $study): array
    {
        $findings = [];
        $proposals = UnitDesignProposal::query()->where('study_id', $study->getKey())->with(['existingUnit', 'parentExisting'])->orderBy('sequence')->get();
        $responsibilityCounts = UnitResponsibility::query()->whereIn('unit_design_proposal_id', $proposals->pluck('id'))->selectRaw('unit_design_proposal_id, count(*) as c')->groupBy('unit_design_proposal_id')->pluck('c', 'unit_design_proposal_id');
        $deactivated = $proposals->where('proposal_type', 'deactivate')->pluck('existing_unit_id')->filter()->flip();

        foreach ($proposals as $proposal) {
            $subject = ['type' => 'proposal', 'id' => $proposal->id, 'code' => null, 'name' => $proposal->name_en];
            $unit = $proposal->existingUnit;

            if ($proposal->proposal_type === 'create') {
                if ($proposal->proposed_unit_type_id === null) {
                    $findings[] = $this->finding('error', 'proposal_without_unit_type', $subject);
                }
                if (trim((string) $proposal->name_en) === '') {
                    $findings[] = $this->finding('error', 'proposal_without_name', $subject);
                }
            } elseif ($unit === null || $unit->organization_id !== $study->organization_id) {
                $findings[] = $this->finding('error', 'proposal_unit_foreign', $subject);
            }

            if ($proposal->proposal_type === 'deactivate' && $unit !== null) {
                $activePositions = Position::query()->where('organization_unit_id', $unit->id)->where('is_active', true)->count();
                if ($activePositions > 0) {
                    $findings[] = $this->finding('error', 'deactivate_unit_has_active_positions', $subject, ['count' => $activePositions, 'unit' => $unit->code]);
                }
                $liveChildren = OrganizationUnit::query()->where('parent_unit_id', $unit->id)->where('status', 'active')->get(['id', 'code'])->reject(fn ($c) => $deactivated->has($c->id));
                if ($liveChildren->isNotEmpty()) {
                    $findings[] = $this->finding('error', 'deactivate_unit_has_children', $subject, ['count' => $liveChildren->count(), 'unit' => $unit->code]);
                }
            }

            if ($proposal->proposal_type === 'move' && $unit !== null && $proposal->parent_existing_unit_id !== null) {
                if ($proposal->parent_existing_unit_id === $unit->id || $this->isDescendant($proposal->parent_existing_unit_id, $unit->id)) {
                    $findings[] = $this->finding('error', 'move_creates_cycle', $subject, ['unit' => $unit->code]);
                }
            }

            if ($proposal->proposal_type !== 'deactivate' && (int) ($responsibilityCounts[$proposal->id] ?? 0) === 0) {
                $findings[] = $this->finding('warning', 'proposal_without_responsibilities', $subject);
            }
        }

        $findings[] = $this->finding('info', 'proposal_count', ['type' => 'study', 'id' => $study->id, 'code' => $study->reference_number, 'name' => $study->title_en], ['count' => $proposals->count()]);

        return $this->report($findings);
    }

    /** True when $candidateId sits anywhere under $rootId in the current tree. */
    private function isDescendant(string $candidateId, string $rootId): bool
    {
        $byId = OrganizationUnit::query()->where('organization_id', OrganizationUnit::query()->whereKey($rootId)->value('organization_id'))->get(['id', 'parent_unit_id'])->keyBy('id');
        $seen = [];
        for ($node = $byId->get($candidateId); $node !== null && ! isset($seen[$node->id]); $node = $node->parent_unit_id === null ? null : $byId->get($node->parent_unit_id)) {
            $seen[$node->id] = true;
            if ($node->parent_unit_id === $rootId) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function unitSubject(?OrganizationUnit $unit): array
    {
        return ['type' => 'unit', 'id' => $unit?->id, 'code' => $unit?->code, 'name' => $unit?->name_en];
    }

    /**
     * @param  array<string, mixed>  $subject
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function finding(string $severity, string $code, array $subject, array $params = []): array
    {
        $label = trim(implode(' ', array_filter([$subject['code'] ?? null, $subject['name'] ?? null])));

        return [
            'severity' => $severity,
            'code' => $code,
            'message' => __('organization-design.validation.'.$code, ['subject' => $label !== '' ? $label : '—', ...$params]),
            'subject' => $subject,
            'params' => $params,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return array{ok: bool, summary: array{errors: int, warnings: int, infos: int}, findings: list<array<string, mixed>>, generated_at: string}
     */
    private function report(array $findings): array
    {
        $order = ['error' => 0, 'warning' => 1, 'info' => 2];
        usort($findings, fn ($a, $b) => [$order[$a['severity']], $a['code']] <=> [$order[$b['severity']], $b['code']]);
        $counts = collect($findings)->countBy('severity');

        return [
            'ok' => ($counts['error'] ?? 0) === 0,
            'summary' => ['errors' => $counts['error'] ?? 0, 'warnings' => $counts['warning'] ?? 0, 'infos' => $counts['info'] ?? 0],
            'findings' => array_values($findings),
            'generated_at' => now()->toISOString(),
        ];
    }
}
