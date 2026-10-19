<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Models\LegacyBaselineSnapshot;

/**
 * Compares two frozen baseline snapshots (brief §92): units, positions and
 * structural/functional relationships added, removed or changed between
 * the older and the newer picture of an organization. Units and positions
 * are matched by code, so a record recreated with a new id but the same
 * code reads as "changed", not as one removal and one addition.
 */
class BaselineComparisonService
{
    private const UNIT_FIELDS = ['name_en', 'name_am', 'unit_type', 'parent_code', 'status'];

    private const POSITION_FIELDS = ['title_en', 'title_am', 'unit_code', 'grade_level', 'occupation_id'];

    /** @return array<string, mixed> */
    public function compare(LegacyBaselineSnapshot $older, LegacyBaselineSnapshot $newer): array
    {
        $olderUnits = $this->units($older);
        $newerUnits = $this->units($newer);
        $olderPositions = $this->positions($older, $olderUnits);
        $newerPositions = $this->positions($newer, $newerUnits);

        return [
            'units' => $this->diff($olderUnits, $newerUnits, self::UNIT_FIELDS),
            'positions' => $this->diff($olderPositions, $newerPositions, self::POSITION_FIELDS),
            'relationships' => $this->relationshipDiff($older, $newer, $olderUnits, $newerUnits),
            'counts' => ['older' => $older->counts, 'newer' => $newer->counts],
        ];
    }

    /** @return array<string, array<string, mixed>> code => unit, with the parent named by code */
    private function units(LegacyBaselineSnapshot $snapshot): array
    {
        $byId = collect($snapshot->units)->keyBy('id');

        return collect($snapshot->units)->mapWithKeys(fn (array $u) => [$u['code'] => [
            ...$u, 'parent_code' => $u['parent_unit_id'] ? ($byId[$u['parent_unit_id']]['code'] ?? null) : null,
        ]])->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function positions(LegacyBaselineSnapshot $snapshot, array $units): array
    {
        $codeById = collect($units)->mapWithKeys(fn ($u) => [$u['id'] => $u['code']]);

        return collect($snapshot->positions)->mapWithKeys(fn (array $p) => [$p['code'] => [...$p, 'unit_code' => $codeById[$p['unit_id']] ?? null]])->all();
    }

    /** @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>} */
    private function diff(array $older, array $newer, array $fields): array
    {
        $added = array_values(array_diff_key($newer, $older));
        $removed = array_values(array_diff_key($older, $newer));
        $changed = [];
        foreach (array_intersect_key($newer, $older) as $code => $after) {
            $before = $older[$code];
            $fieldChanges = [];
            foreach ($fields as $field) {
                if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                    $fieldChanges[$field] = ['from' => $before[$field] ?? null, 'to' => $after[$field] ?? null];
                }
            }
            if ($fieldChanges !== []) {
                $changed[] = ['code' => $code, 'name' => $after['name_en'] ?? $after['title_en'] ?? null, 'changes' => $fieldChanges];
            }
        }

        $slim = fn (array $r) => ['code' => $r['code'], 'name' => $r['name_en'] ?? $r['title_en'] ?? null];

        return ['added' => array_map($slim, $added), 'removed' => array_map($slim, $removed), 'changed' => $changed];
    }

    /** @return array{added: list<string>, removed: list<string>} relationships named "source → type → target" */
    private function relationshipDiff(LegacyBaselineSnapshot $older, LegacyBaselineSnapshot $newer, array $olderUnits, array $newerUnits): array
    {
        $key = function (array $r, array $units): string {
            $codeById = collect($units)->mapWithKeys(fn ($u) => [$u['id'] => $u['code']]);

            return ($codeById[$r['source_unit_id']] ?? '?').' → '.$r['relationship_type'].' → '.($r['target_type'] === 'organization_unit' ? ($codeById[$r['target_id']] ?? $r['target_id']) : $r['target_type'].':'.$r['target_id']);
        };
        $before = array_unique(array_map(fn ($r) => $key($r, $olderUnits), $older->relationships));
        $after = array_unique(array_map(fn ($r) => $key($r, $newerUnits), $newer->relationships));

        return ['added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after))];
    }
}
