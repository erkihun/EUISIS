<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\OrganizationUnits\CreateOrganizationUnitAction;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationUnitStatus;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipTargetType;
use App\Models\OrganizationUnitRelationship;
use App\Models\OrganizationUnitType;
use App\Services\OrganizationRelationships\OrganizationUnitRelationshipService;
use App\Support\Demo\DemoDataset;
use Illuminate\Support\Facades\DB;

/**
 * Units of each demo organization (structural parent via parent_unit_id,
 * codes from the unit code rule) and one functional reporting relationship
 * between two units. The functional target is a unit, never an
 * organization, so position code ownership is unaffected.
 */
class DemoStructureSeeder extends DemoSeeder
{
    public function run(CreateOrganizationUnitAction $createUnit, OrganizationUnitRelationshipService $relationships): void
    {
        $maker = DemoDataset::requireUser(DemoDataset::MAKER_EMAIL);
        $epoch = DemoDataset::epoch()->toDateString();
        $unitTypes = OrganizationUnitType::query()->pluck('id', 'code');

        foreach (DemoDataset::businessOrganizationKeys() as $organizationKey) {
            $organization = DemoDataset::requireOrganization($organizationKey);

            DB::transaction(function () use ($organization, $organizationKey, $createUnit, $relationships, $maker, $epoch, $unitTypes): void {
                $sort = 0;
                foreach (DemoDataset::units($organizationKey) as $unitKey => $definition) {
                    $sort++;
                    if (DemoDataset::unit($organization, $unitKey) !== null) {
                        continue;
                    }

                    $createUnit->execute([
                        'organization_id' => $organization->id,
                        'parent_unit_id' => $definition['parent'] !== null ? DemoDataset::unit($organization, $definition['parent'])?->id : null,
                        'organization_unit_type_id' => $unitTypes[$definition['type']] ?? null,
                        'unit_type' => $definition['type'],
                        'name_en' => $definition['name_en'],
                        'name_am' => $definition['name_am'],
                        'status' => OrganizationUnitStatus::Active->value,
                        'effective_from' => $epoch,
                        'sort_order' => $sort,
                        'metadata' => DemoDataset::tag($unitKey),
                    ], $maker);
                }

                $link = DemoDataset::functionalRelationship();
                $source = DemoDataset::unit($organization, $link['source']);
                $target = DemoDataset::unit($organization, $link['target']);
                $exists = OrganizationUnitRelationship::query()->where('source_unit_id', $source->id)
                    ->where('target_type', RelationshipTargetType::OrganizationUnit->value)->where('target_id', $target->id)
                    ->where('relationship_type', OrganizationRelationshipType::FunctionalReporting->value)->exists();
                if (! $exists) {
                    $relationships->create([
                        'source_unit_id' => $source->id,
                        'target_type' => RelationshipTargetType::OrganizationUnit->value,
                        'target_id' => $target->id,
                        'relationship_type' => OrganizationRelationshipType::FunctionalReporting->value,
                        'is_primary' => false,
                        'effective_from' => $epoch,
                        'status' => RelationshipStatus::Active->value,
                    ], $maker);
                }
            });
        }
    }
}
