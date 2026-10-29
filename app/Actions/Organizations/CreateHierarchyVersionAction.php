<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Models\OrganizationEdge;
use App\Models\User;
use Illuminate\Support\Facades\DB;

readonly class CreateHierarchyVersionAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLogAction) {}

    /**
     * A new draft starts as a copy of the published structure, so a change is
     * made to the whole tree. An empty draft, once published, would replace
     * the live structure (and everyone's organization scope) with only the
     * edges added to it.
     */
    public function execute(array $attributes, User $actor): HierarchyVersion
    {
        return DB::transaction(function () use ($attributes, $actor): HierarchyVersion {
            $version = HierarchyVersion::query()->create([
                'version_name' => $attributes['version_name'],
                'notes' => $attributes['notes'] ?? null,
                'source_document' => $attributes['source_document'] ?? null,
                'status' => HierarchyVersionStatus::Draft,
                'effective_from' => $attributes['effective_from'],
                'effective_to' => $attributes['effective_to'] ?? null,
            ]);

            $published = HierarchyVersion::query()
                ->where('status', HierarchyVersionStatus::Published)
                ->latest('effective_from')
                ->latest('created_at')
                ->first();

            $copied = 0;
            if ($published !== null) {
                OrganizationEdge::query()
                    ->where('hierarchy_version_id', $published->id)
                    ->orderBy('id')
                    ->each(function (OrganizationEdge $edge) use ($version, &$copied): void {
                        OrganizationEdge::query()->create([
                            'hierarchy_version_id' => $version->id,
                            'parent_organization_id' => $edge->parent_organization_id,
                            'child_organization_id' => $edge->child_organization_id,
                            'relationship_type' => $edge->relationship_type,
                            'effective_from' => $edge->effective_from,
                            'effective_to' => $edge->effective_to,
                        ]);
                        $copied++;
                    });
            }

            $this->writeAuditLogAction->execute(
                AuditEventType::HierarchyVersionCreated,
                $actor,
                $version,
                newValues: $version->toArray() + ['copied_from_version_id' => $published?->id, 'copied_edges' => $copied],
            );

            return $version;
        });
    }
}
