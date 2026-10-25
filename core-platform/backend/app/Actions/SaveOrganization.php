<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CodeGenerator;
use App\Services\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes an organization (EUISIS CreateOrganizationAction /
 * UpdateOrganizationAction): a blank code is generated, and the parent is the
 * structural edge in the published hierarchy. A scoped account works only
 * inside its own subtree, may not move its own top organization, and no
 * organization may sit under one of its own descendants.
 */
final readonly class SaveOrganization
{
    public const STATUSES = ['draft', 'active', 'inactive', 'merged', 'dissolved', 'archived'];

    public function __construct(private AuditWriter $audit, private OrganizationScope $scope, private CodeGenerator $codes) {}

    /** @param array<string, mixed> $data organization_type_id, code, name_en, name_am, status, effective_from, parent_organization_id */
    public function execute(?Organization $organization, array $data, User $actor): Organization
    {
        return DB::transaction(function () use ($organization, $data, $actor): Organization {
            $parentId = $data['parent_organization_id'] ?? null;
            unset($data['parent_organization_id']);
            $version = HierarchyVersion::current();

            if ($organization !== null && ! $this->scope->allows($actor, (string) $organization->id)) {
                throw ValidationException::withMessages(['organization' => 'This organization is outside your organizations.']);
            }
            if ($parentId !== null && ! $this->scope->allows($actor, (string) $parentId)) {
                throw ValidationException::withMessages(['parent_organization_id' => 'The parent organization is outside your organizations.']);
            }
            if ($organization === null && $parentId === null && ! $actor->isCityWide()) {
                throw ValidationException::withMessages(['parent_organization_id' => 'Choose a parent organization inside your scope.']);
            }
            if ($parentId !== null && $version === null) {
                throw ValidationException::withMessages(['parent_organization_id' => 'There is no published hierarchy to place the organization in.']);
            }
            if ($organization !== null && $parentId !== null && in_array((string) $parentId, $this->scope->subtree((string) $organization->id), true)) {
                throw ValidationException::withMessages(['parent_organization_id' => 'An organization cannot sit under itself or one of its own organizations.']);
            }

            if (array_key_exists('code', $data) || $organization === null) {
                $data['code'] = $this->codes->code('organization', $data['code'] ?? null);
                $taken = Organization::withTrashed()->where('code', $data['code'])->when($organization, fn ($q) => $q->whereKeyNot($organization->id))->exists();
                if ($taken) {
                    throw ValidationException::withMessages(['code' => 'This code is already used.']);
                }
            }

            $old = $organization?->only(array_keys($data));
            $organization ??= new Organization;
            $organization->fill($data + ($organization->exists ? [] : ['status' => 'active', 'effective_from' => now()->toDateString()]))->save();

            $oldParent = $version === null ? null : OrganizationEdge::query()
                ->where('hierarchy_version_id', $version->id)
                ->where('relationship_type', OrganizationEdge::STRUCTURAL)
                ->where('child_organization_id', $organization->id)
                ->value('parent_organization_id');
            if ($version !== null && (string) $oldParent !== (string) $parentId) {
                if ($oldParent !== null && $actor->scope_organization_id === $organization->id) {
                    throw ValidationException::withMessages(['parent_organization_id' => 'You cannot move the organization your account is scoped to.']);
                }
                OrganizationEdge::query()->where('hierarchy_version_id', $version->id)->where('relationship_type', OrganizationEdge::STRUCTURAL)
                    ->where('child_organization_id', $organization->id)->delete();
                if ($parentId !== null) {
                    OrganizationEdge::query()->create([
                        'hierarchy_version_id' => $version->id,
                        'parent_organization_id' => $parentId,
                        'child_organization_id' => $organization->id,
                        'relationship_type' => OrganizationEdge::STRUCTURAL,
                        'effective_from' => now()->toDateString(),
                    ]);
                }
            }

            $new = $organization->only(array_keys($data)) + ['parent_organization_id' => $parentId];
            $this->audit->write($old === null ? 'organization.created' : 'organization.updated', $actor, $organization, (string) $organization->id,
                $old === null ? null : $old + ['parent_organization_id' => $oldParent], $new);

            return $organization->fresh(['type']);
        });
    }
}
