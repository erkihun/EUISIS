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

readonly class DeleteOrganizationEdgeAction
{
    public function __construct(
        private OrganizationTreeService $organizationTreeService,
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function execute(
        HierarchyVersion $hierarchyVersion,
        OrganizationEdge $edge,
        User $actor,
        ?Request $request = null,
    ): void {
        $this->organizationTreeService->assertDraftVersion($hierarchyVersion);

        $oldValues = $edge->toArray();

        $edge->delete();

        $this->writeAuditLogAction->execute(
            AuditEventType::HierarchyRelationRemoved,
            $actor,
            $edge,
            organizationId: $oldValues['parent_organization_id'] ?? null,
            oldValues: [
                'hierarchy_version_id' => $hierarchyVersion->id,
                'edge' => $oldValues,
            ],
            request: $request,
        );
    }
}
