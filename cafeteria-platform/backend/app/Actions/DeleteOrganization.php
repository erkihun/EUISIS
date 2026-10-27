<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\OrganizationScope;
use App\Services\OrganizationUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes an organization that nothing refers to (one created by mistake),
 * with a written reason. Anything with history is archived instead; the
 * refusal names every reason (OrganizationUsage). The row is soft-deleted and
 * keeps its code, so the code is never given to another organization.
 */
final readonly class DeleteOrganization
{
    public function __construct(private AuditWriter $audit, private OrganizationScope $scope, private OrganizationUsage $usage) {}

    public function execute(Organization $organization, string $reason, User $actor): void
    {
        DB::transaction(function () use ($organization, $reason, $actor): void {
            // Serialized with hierarchy changes, which could place the organization meanwhile.
            HierarchyVersion::query()->orderBy('id')->lockForUpdate()->get(['id']);
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            if (! $this->scope->allows($actor, (string) $organization->id)) {
                throw ValidationException::withMessages(['organization' => 'This organization is outside your organizations.']);
            }
            if ($actor->scope_organization_id === $organization->id) {
                throw ValidationException::withMessages(['organization' => 'You cannot delete the organization your account is scoped to.']);
            }
            $blockers = $this->usage->deletionBlockers($organization);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['organization' => array_values($blockers)]);
            }

            $organization->forceFill(['deleted_by' => $actor->getKey(), 'deletion_reason' => $reason])->save();
            $organization->delete();
            $this->audit->write('organization.deleted', $actor, $organization, (string) $organization->id,
                $organization->only(['code', 'name_en', 'status']), null, $reason);
        });
    }
}
