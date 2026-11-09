<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\HierarchyVersion;
use App\Models\OrganizationEdge;
use App\Models\User;
use App\Services\Organizations\OrganizationTreeService;
use Illuminate\Http\Request;

readonly class UpdateOrganizationEdgeAction
{
    public function __construct(
        private OrganizationTreeService $organizationTreeService,
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(
        HierarchyVersion $hierarchyVersion,
        OrganizationEdge $edge,
        array $attributes,
        User $actor,
        ?Request $request = null,
    ): OrganizationEdge {
        $this->organizationTreeService->validateEdgeMutation(
            $hierarchyVersion,
            $actor,
            $attributes['parent_organization_id'],
            $attributes['child_organization_id'],
            $edge->id,
        );

        $oldValues = $edge->toArray();

        $edge->update([
            'parent_organization_id' => $attributes['parent_organization_id'],
            'child_organization_id' => $attributes['child_organization_id'],
            'relationship_type' => $attributes['relationship_type'],
            'effective_from' => $attributes['effective_from'] ?? null,
            'effective_to' => $attributes['effective_to'] ?? null,
        ]);

        $freshEdge = $edge->fresh();

        $this->writeAuditLogAction->execute(
            AuditEventType::HierarchyRelationUpdated,
            $actor,
            $freshEdge,
            organizationId: $attributes['parent_organization_id'],
            oldValues: [
                'hierarchy_version_id' => $hierarchyVersion->id,
                'edge' => $oldValues,
            ],
            newValues: [
                'hierarchy_version_id' => $hierarchyVersion->id,
                'edge' => $freshEdge?->toArray(),
            ],
            request: $request,
        );

        return $freshEdge;
    }
}
