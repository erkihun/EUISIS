<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Organizations\CreateHierarchyVersionAction;
use App\Actions\Organizations\CreateOrganizationAction;
use App\Actions\Organizations\CreateOrganizationEdgeAction;
use App\Actions\Organizations\PublishHierarchyVersionAction;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationStatus;
use App\Models\HierarchyVersion;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\User;
use App\Support\Demo\DemoDataset;

/**
 * The demo root and the five demo organizations, with codes from the
 * organization code rule, and their place in a hierarchy version.
 *
 * Publishing a version archives the published one, so the demo never
 * replaces an existing structure:
 *   - no published version yet: the demo version (demo organizations only)
 *     is published, so subtree scopes work at once;
 *   - a published version exists: the demo version is a DRAFT holding the
 *     current published edges plus the demo organizations. Publishing it later
 *     keeps everything and adds the demo tree; the seeder never publishes it.
 */
class DemoOrganizationSeeder extends DemoSeeder
{
    public function run(
        CreateOrganizationAction $createOrganization,
        CreateHierarchyVersionAction $createVersion,
        CreateOrganizationEdgeAction $createEdge,
        PublishHierarchyVersionAction $publish,
    ): void {
        $maker = DemoDataset::requireUser(DemoDataset::MAKER_EMAIL);
        $epoch = DemoDataset::epoch();

        foreach (DemoDataset::organizations() as $key => $definition) {
            if (DemoDataset::organization($key) !== null) {
                continue;
            }

            $createOrganization->execute([
                'organization_type_id' => OrganizationType::query()->where('code', $definition['type'])->firstOrFail()->id,
                'name_en' => $definition['name_en'],
                'name_am' => $definition['name_am'],
                'status' => OrganizationStatus::Active->value,
                'effective_from' => $epoch->toDateString(),
                'metadata' => DemoDataset::tag($key, array_filter([
                    'purpose' => $definition['purpose'],
                    'seeded_on' => $key === DemoDataset::ROOT ? DemoDataset::anchor()->toDateString() : null,
                ])),
            ], $maker);
        }

        $this->placeInHierarchy($maker, $epoch->toDateString(), $createVersion, $createEdge, $publish);
    }

    private function placeInHierarchy(
        User $maker,
        string $epoch,
        CreateHierarchyVersionAction $createVersion,
        CreateOrganizationEdgeAction $createEdge,
        PublishHierarchyVersionAction $publish,
    ): void {
        $version = HierarchyVersion::query()->where('version_name', DemoDataset::HIERARCHY_VERSION_NAME)->first();

        if ($version === null) {
            $published = HierarchyVersion::query()->where('status', HierarchyVersionStatus::Published)->first();
            $version = $createVersion->execute([
                'version_name' => DemoDataset::HIERARCHY_VERSION_NAME,
                // Following an existing structure it starts today, so publishing
                // it later ends the current version yesterday, not a year ago.
                'effective_from' => $published === null ? $epoch : today()->toDateString(),
                'notes' => $published === null
                    ? 'Synthetic demo hierarchy (development / QA / UAT only).'
                    : 'DRAFT: the current published structure plus the synthetic demo organizations. Publish only on a development or UAT database.',
            ], $maker);

            if ($published !== null) {
                $this->copyEdges($published, $version, $maker, $createEdge);
            }
        }

        if ($version->status !== HierarchyVersionStatus::Draft) {
            return;
        }

        foreach (DemoDataset::organizations() as $key => $definition) {
            if ($definition['parent'] === null) {
                continue;
            }
            $parent = DemoDataset::requireOrganization($definition['parent']);
            $child = DemoDataset::requireOrganization($key);
            $exists = OrganizationEdge::query()->where('hierarchy_version_id', $version->id)
                ->where('parent_organization_id', $parent->id)->where('child_organization_id', $child->id)->exists();
            if (! $exists) {
                $createEdge->execute($version, [
                    'parent_organization_id' => $parent->id,
                    'child_organization_id' => $child->id,
                    'relationship_type' => OrganizationRelationshipType::ReportsTo->value,
                    'effective_from' => $epoch,
                ], $maker);
            }
        }

        $otherPublished = HierarchyVersion::query()->whereKeyNot($version->id)->where('status', HierarchyVersionStatus::Published)->exists();
        if ($otherPublished) {
            $this->command?->warn('A hierarchy version is already published: "'.DemoDataset::HIERARCHY_VERSION_NAME.'" stays a draft (current structure + demo organizations). Subtree scopes on demo organizations apply once it is published.');

            return;
        }

        $publish->execute($version, $maker);
    }

    private function copyEdges(HierarchyVersion $from, HierarchyVersion $to, User $maker, CreateOrganizationEdgeAction $createEdge): void
    {
        OrganizationEdge::query()->where('hierarchy_version_id', $from->id)->orderBy('created_at')->get()
            ->each(fn (OrganizationEdge $edge) => $createEdge->execute($to, [
                'parent_organization_id' => $edge->parent_organization_id,
                'child_organization_id' => $edge->child_organization_id,
                'relationship_type' => $edge->relationship_type->value,
                'effective_from' => $edge->effective_from?->toDateString(),
                'effective_to' => $edge->effective_to?->toDateString(),
            ], $maker));
    }
}
