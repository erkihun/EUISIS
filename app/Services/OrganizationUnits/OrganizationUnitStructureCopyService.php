<?php

declare(strict_types=1);

namespace App\Services\OrganizationUnits;

use App\Actions\CodeRules\GenerateCodeAction;
use App\Enums\CodeRuleEntityType;
use App\Models\CodeRule;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\CodeGeneration\CodeRuleResolver;
use Illuminate\Support\Collection;

class OrganizationUnitStructureCopyService
{
    /** @var array<string, CodeRule|null> */
    private array $codeRuleCache = [];

    public function __construct(
        private readonly GenerateCodeAction $generateCodeAction,
        private readonly CodeRuleResolver $codeRuleResolver,
    ) {}

    /**
     * Recursively copy a tree of OrganizationUnits (and optionally their Positions)
     * into the target organization.
     *
     * @param  Collection<int, OrganizationUnit>  $sourceUnits  Top-level units to copy (children are loaded recursively).
     * @param  string|null  $parentUnitId  Parent unit in the target org (null = root).
     * @param  array{
     *     copy_positions: bool,
     *     copy_functional_relationships: bool,
     *     name_prefix: string|null,
     *     name_suffix: string|null,
     *     status: string,
     *     effective_from: string|null,
     * }  $options
     * @return array{units: int, positions: int}
     */
    public function copyTree(
        Collection $sourceUnits,
        string $targetOrgId,
        ?string $parentUnitId,
        array $options,
        User $actor,
    ): array {
        // Reset the cache at the start of each copy operation so stale rules
        // from a previous call on the same service instance cannot bleed through.
        $this->codeRuleCache = [];

        $unitCount = 0;
        $positionCount = 0;

        foreach ($sourceUnits as $sourceUnit) {
            [$newUnitCount, $newPositionCount] = $this->copyUnit(
                $sourceUnit,
                $targetOrgId,
                $parentUnitId,
                $options,
                $actor,
            );
            $unitCount += $newUnitCount;
            $positionCount += $newPositionCount;
        }

        return ['units' => $unitCount, 'positions' => $positionCount];
    }

    /**
     * Copy a single unit and all its descendants. Returns [unitsCreated, positionsCreated].
     *
     * @return array{int, int}
     */
    private function copyUnit(
        OrganizationUnit $source,
        string $targetOrgId,
        ?string $parentUnitId,
        array $options,
        User $actor,
    ): array {
        $unitContext = [
            'organization_id' => $targetOrgId,
            'organization_unit_type_id' => $source->organization_unit_type_id,
        ];

        $code = $this->generateCodeAction->execute(
            CodeRuleEntityType::OrganizationUnit,
            $unitContext,
            $actor,
            null,
            'code',
            null,
            $this->resolveCodeRuleCached(CodeRuleEntityType::OrganizationUnit, $unitContext),
        );

        $nameEn = ($options['name_prefix'] ?? '').$source->name_en.($options['name_suffix'] ?? '');
        $nameAm = $source->name_am !== null
            ? ($options['name_prefix'] ?? '').$source->name_am.($options['name_suffix'] ?? '')
            : null;

        $newUnit = OrganizationUnit::query()->create([
            'organization_id' => $targetOrgId,
            'parent_unit_id' => $parentUnitId,
            'organization_unit_type_id' => $source->organization_unit_type_id,
            'unit_type' => $source->unit_type,
            'code' => $code,
            'name_en' => $nameEn,
            'name_am' => $nameAm,
            'description_en' => $source->description_en,
            'description_am' => $source->description_am,
            'status' => $options['status'],
            'effective_from' => $options['effective_from'] ?? null,
            'sort_order' => $source->sort_order,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);

        $unitCount = 1;
        $positionCount = 0;

        if ($options['copy_positions']) {
            $positionCount += $this->copyPositions($source, $newUnit, $targetOrgId, $options, $actor);
        }

        $children = $source->children()->get();
        foreach ($children as $child) {
            [$childUnits, $childPositions] = $this->copyUnit(
                $child,
                $targetOrgId,
                $newUnit->id,
                $options,
                $actor,
            );
            $unitCount += $childUnits;
            $positionCount += $childPositions;
        }

        return [$unitCount, $positionCount];
    }

    /**
     * Copy all positions belonging to a source unit into the new unit.
     */
    private function copyPositions(
        OrganizationUnit $sourceUnit,
        OrganizationUnit $targetUnit,
        string $targetOrgId,
        array $options,
        User $actor,
    ): int {
        $positions = Position::query()
            ->where('organization_unit_id', $sourceUnit->id)
            ->whereNull('deleted_at')
            ->get();

        if ($positions->isEmpty()) {
            return 0;
        }

        // Provide organization_unit_type_id so CodeRuleResolver does not need
        // to issue a separate query to look it up from organization_unit_id.
        $positionContext = [
            'organization_id' => $targetOrgId,
            'organization_unit_id' => $targetUnit->id,
            'organization_unit_type_id' => $targetUnit->organization_unit_type_id,
        ];

        $positionRule = $this->resolveCodeRuleCached(CodeRuleEntityType::Position, $positionContext);

        $count = 0;

        foreach ($positions as $position) {
            $code = $this->generateCodeAction->execute(
                CodeRuleEntityType::Position,
                $positionContext,
                $actor,
                null,
                'code',
                null,
                $positionRule,
            );

            $titleEn = ($options['name_prefix'] ?? '').$position->title_en.($options['name_suffix'] ?? '');
            $titleAm = $position->title_am !== null
                ? ($options['name_prefix'] ?? '').$position->title_am.($options['name_suffix'] ?? '')
                : null;

            $jobPositionCode = $code.'-JPC';

            Position::query()->create([
                'organization_id' => $targetOrgId,
                'organization_unit_id' => $targetUnit->id,
                'occupation_id' => $position->occupation_id,
                'job_position_code' => $jobPositionCode,
                'title_en' => $titleEn,
                'title_am' => $titleAm,
                'code' => $code,
                'description_en' => $position->description_en,
                'description_am' => $position->description_am,
                'grade_level' => $position->grade_level,
                'job_family' => $position->job_family,
                'is_active' => $position->is_active,
                'effective_from' => $options['effective_from'] ?? $position->effective_from,
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Resolve a CodeRule for the given entity type and context, caching the result
     * for the duration of the current copy operation so that CodeRuleResolver does
     * not issue repeated identical queries for every unit/position in the tree.
     *
     * Cache key is intentionally coarse: entity_type + organization_id +
     * organization_unit_type_id. Finer-grained context fields (organization_unit_id)
     * affect sequence scoping inside CodeGeneratorService but do NOT change which
     * CodeRule is selected — so they are safe to exclude from the cache key.
     */
    private function resolveCodeRuleCached(CodeRuleEntityType $entityType, array $context): ?CodeRule
    {
        $key = implode('|', [
            $entityType->value,
            $context['organization_id'] ?? '',
            $context['organization_unit_type_id'] ?? '',
        ]);

        if (! array_key_exists($key, $this->codeRuleCache)) {
            $this->codeRuleCache[$key] = $this->codeRuleResolver->resolve($entityType, $context);
        }

        return $this->codeRuleCache[$key];
    }
}
