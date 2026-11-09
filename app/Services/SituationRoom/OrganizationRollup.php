<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use App\Models\Organization;
use App\Models\OrganizationClosurePath;

/**
 * How counts already confined to a scope are grouped "by organization".
 *
 * Looking at an organization, the breakdown is its own office plus one row
 * per direct child, each child carrying its whole subtree (a Subcity row
 * includes its Woredas). With no focus, the viewer's top organizations are
 * the rows; a viewer with a single top organization is taken one level down
 * automatically. Only organizations inside the scope are ever mapped, so a
 * row can never pull in a sibling.
 *
 * Without a published hierarchy there is no authoritative parent/child
 * relation: every organization is its own row (`hierarchy` = false), and the
 * room says so rather than guessing a reporting line.
 */
final class OrganizationRollup
{
    public function __construct(private readonly SituationScopeResolver $resolver) {}

    /**
     * @return array{anchor: ?string, hierarchy: bool, groups: list<array{id: string, label: array{en: ?string, am: ?string}, own: bool}>, map: array<string, string>}
     */
    public function for(SituationScope $scope): array
    {
        return app(SituationMemo::class)->remember('rollup|'.$scope->fingerprint().'|'.$scope->focusOrganizationId, fn (): array => $this->build($scope));
    }

    /**
     * @return array{anchor: ?string, hierarchy: bool, groups: list<array{id: string, label: array{en: ?string, am: ?string}, own: bool}>, map: array<string, string>}
     */
    private function build(SituationScope $scope): array
    {
        $versionId = $this->resolver->publishedVersionId();
        $inScope = $scope->organizationIds;

        if ($versionId === null) {
            return $this->flat($inScope);
        }

        $anchor = $scope->focusOrganizationId;
        $roots = [];

        if ($anchor === null) {
            $roots = $this->roots($versionId, $inScope);

            if (count($roots) === 1) {
                $anchor = $roots[0];
            }
        }

        $groupIds = $anchor === null
            ? $roots
            : array_merge([$anchor], $this->allowed($this->resolver->children($anchor), $inScope));

        $map = [];
        if ($groupIds !== []) {
            $paths = OrganizationClosurePath::query()
                ->where('hierarchy_version_id', $versionId)
                ->whereIn('ancestor_organization_id', array_values(array_diff($groupIds, $anchor === null ? [] : [$anchor])))
                ->get(['ancestor_organization_id', 'descendant_organization_id']);

            foreach ($paths as $path) {
                $map[(string) $path->descendant_organization_id] = (string) $path->ancestor_organization_id;
            }
            foreach ($groupIds as $id) {
                $map[$id] = $id;
            }
        }

        if ($inScope !== null) {
            $map = array_intersect_key($map, array_flip($inScope));
        }

        return [
            'anchor' => $anchor,
            'hierarchy' => true,
            'groups' => $this->labelled($groupIds, $anchor),
            'map' => $map,
        ];
    }

    /**
     * Sum per-organization values into the roll-up rows. Values for
     * organizations the hierarchy does not place go to an "unplaced" row,
     * so the rows always add up to the scope total.
     *
     * @param  array<string, int|float>  $byOrganization
     * @param  array{groups: list<array{id: string, label: array{en: ?string, am: ?string}, own: bool}>, map: array<string, string>}  $rollup
     * @return array<string, int|float>
     */
    public static function sum(array $byOrganization, array $rollup): array
    {
        $totals = array_fill_keys(array_column($rollup['groups'], 'id'), 0);

        foreach ($byOrganization as $organizationId => $value) {
            $group = $rollup['map'][(string) $organizationId] ?? '__unplaced';
            $totals[$group] = ($totals[$group] ?? 0) + $value;
        }

        return $totals;
    }

    /**
     * Top organizations of the scope on the published hierarchy: in scope,
     * with no ancestor that is also in scope.
     *
     * @param  list<string>|null  $inScope
     * @return list<string>
     */
    private function roots(string $versionId, ?array $inScope): array
    {
        $candidates = $inScope ?? OrganizationClosurePath::query()
            ->where('hierarchy_version_id', $versionId)
            ->where('depth', 0)
            ->pluck('descendant_organization_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($candidates === []) {
            return [];
        }

        $hasAncestor = OrganizationClosurePath::query()
            ->where('hierarchy_version_id', $versionId)
            ->where('depth', '>', 0)
            ->whereIn('descendant_organization_id', $candidates)
            ->when($inScope !== null, fn ($q) => $q->whereIn('ancestor_organization_id', $inScope))
            ->distinct()
            ->pluck('descendant_organization_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        return array_values(array_diff($candidates, $hasAncestor));
    }

    /**
     * @param  list<string>|null  $inScope
     * @return array{anchor: null, hierarchy: bool, groups: list<array{id: string, label: array{en: ?string, am: ?string}, own: bool}>, map: array<string, string>}
     */
    private function flat(?array $inScope): array
    {
        $ids = $inScope ?? Organization::query()->pluck('id')->map(fn ($id): string => (string) $id)->all();

        return ['anchor' => null, 'hierarchy' => false, 'groups' => $this->labelled($ids, null), 'map' => array_combine($ids, $ids) ?: []];
    }

    /**
     * @param  list<string>  $ids
     * @param  list<string>|null  $inScope
     * @return list<string>
     */
    private function allowed(array $ids, ?array $inScope): array
    {
        return $inScope === null ? $ids : array_values(array_intersect($ids, $inScope));
    }

    /**
     * Rows in name order: a roll-up is a breakdown, not a ranking.
     *
     * @param  list<string>  $ids
     * @return list<array{id: string, label: array{en: ?string, am: ?string}, own: bool}>
     */
    private function labelled(array $ids, ?string $anchor): array
    {
        $names = Organization::query()->whereIn('id', $ids)->get(['id', 'name_en', 'name_am'])->keyBy(fn ($o): string => (string) $o->id);

        $groups = array_map(fn (string $id): array => [
            'id' => $id,
            'label' => SituationLabel::names($names[$id]->name_en ?? null, $names[$id]->name_am ?? null),
            'own' => $id === $anchor,
        ], array_values(array_unique($ids)));

        usort($groups, fn (array $a, array $b): int => [$b['own'], mb_strtolower((string) $a['label']['en'])] <=> [$a['own'], mb_strtolower((string) $b['label']['en'])]);

        return $groups;
    }
}
