<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Models\OrganizationClosurePath;
use App\Models\OrganizationEdge;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

readonly class PublishHierarchyVersionAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    public function execute(HierarchyVersion $version, User $actor): HierarchyVersion
    {
        return DB::transaction(function () use ($version, $actor): HierarchyVersion {
            $this->ensurePublishable($version);

            $oldValues = $version->toArray();

            HierarchyVersion::query()
                ->whereKeyNot($version->id)
                ->where('status', HierarchyVersionStatus::Published)
                ->get()
                ->each(function (HierarchyVersion $publishedVersion) use ($version): void {
                    $updates = ['status' => HierarchyVersionStatus::Archived];

                    if ($publishedVersion->effective_to === null && $version->effective_from !== null) {
                        $updates['effective_to'] = $version->effective_from->copy()->subDay()->toDateString();
                    }

                    $publishedVersion->update($updates);
                });

            $version->update([
                'status' => HierarchyVersionStatus::Published,
                'approved_by' => $actor->getKey(),
                'approval_date' => now(),
            ]);

            OrganizationClosurePath::query()->where('hierarchy_version_id', $version->id)->delete();

            $edges = OrganizationEdge::query()
                ->where('hierarchy_version_id', $version->id)
                ->get(['parent_organization_id', 'child_organization_id']);

            $childrenByParent = $edges
                ->groupBy('parent_organization_id')
                ->map(static fn ($group) => $group->pluck('child_organization_id')->values());

            $organizationIds = $edges
                ->flatMap(fn (OrganizationEdge $edge) => [$edge->parent_organization_id, $edge->child_organization_id])
                ->unique()
                ->values();

            foreach ($organizationIds as $organizationId) {
                OrganizationClosurePath::query()->firstOrCreate([
                    'hierarchy_version_id' => $version->id,
                    'ancestor_organization_id' => $organizationId,
                    'descendant_organization_id' => $organizationId,
                ], [
                    'depth' => 0,
                ]);
            }

            foreach ($organizationIds as $organizationId) {
                $queue = $childrenByParent->get($organizationId, collect())
                    ->map(static fn (string $childId) => ['id' => $childId, 'depth' => 1])
                    ->values()
                    ->all();
                $visited = [];

                while ($queue !== []) {
                    $current = array_shift($queue);
                    $currentId = $current['id'];
                    $depth = $current['depth'];

                    if (isset($visited[$currentId])) {
                        continue;
                    }

                    $visited[$currentId] = true;

                    OrganizationClosurePath::query()->updateOrCreate([
                        'hierarchy_version_id' => $version->id,
                        'ancestor_organization_id' => $organizationId,
                        'descendant_organization_id' => $currentId,
                    ], [
                        'depth' => $depth,
                    ]);

                    foreach ($childrenByParent->get($currentId, collect()) as $nextChildId) {
                        $queue[] = [
                            'id' => $nextChildId,
                            'depth' => $depth + 1,
                        ];
                    }
                }
            }

            $this->writeAuditLogAction->execute(
                AuditEventType::HierarchyPublished,
                $actor,
                $version,
                oldValues: $oldValues,
                newValues: $version->fresh()?->toArray(),
            );

            return $version->fresh();
        });
    }

    private function ensurePublishable(HierarchyVersion $version): void
    {
        if ($version->status !== HierarchyVersionStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => __('hierarchy-versions.only_draft_can_be_published'),
            ]);
        }

        $edges = OrganizationEdge::query()
            ->where('hierarchy_version_id', $version->id)
            ->get(['parent_organization_id', 'child_organization_id']);

        if ($edges->isEmpty()) {
            throw ValidationException::withMessages([
                'status' => __('hierarchy-versions.publish_requires_edges'),
            ]);
        }

        $duplicateEdges = $edges
            ->groupBy(fn (OrganizationEdge $edge) => "{$edge->parent_organization_id}:{$edge->child_organization_id}")
            ->contains(fn (Collection $group) => $group->count() > 1);

        if ($duplicateEdges) {
            throw ValidationException::withMessages([
                'status' => __('hierarchy-versions.publish_has_duplicate_edges'),
            ]);
        }

        $graph = $edges
            ->groupBy('parent_organization_id')
            ->map(fn (Collection $group) => $group->pluck('child_organization_id')->values()->all())
            ->all();

        $visited = [];
        $active = [];

        $detectCycle = function (string $node) use (&$detectCycle, &$visited, &$active, $graph): bool {
            if (($active[$node] ?? false) === true) {
                return true;
            }

            if (($visited[$node] ?? false) === true) {
                return false;
            }

            $visited[$node] = true;
            $active[$node] = true;

            foreach ($graph[$node] ?? [] as $childNode) {
                if ($detectCycle($childNode)) {
                    return true;
                }
            }

            $active[$node] = false;

            return false;
        };

        foreach (array_keys($graph) as $node) {
            if ($detectCycle($node)) {
                throw ValidationException::withMessages([
                    'status' => __('hierarchy-versions.publish_has_circular_hierarchy'),
                ]);
            }
        }
    }
}
