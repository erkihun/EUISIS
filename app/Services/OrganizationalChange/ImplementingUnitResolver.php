<?php

declare(strict_types=1);

namespace App\Services\OrganizationalChange;

use App\Enums\OrganizationalChangeRequestType;
use App\Models\OrganizationalChangeRequest;
use App\Models\OrganizationUnit;
use App\Models\User;

/**
 * Routes an approved request to the unit responsible for applying it.
 *
 * The mapping is request type -> implementing unit key -> a configured
 * organization unit. No government department name is hard-coded: the keys are
 * generic ("structure_management", "establishment_management") and the actual
 * unit is resolved from configuration, falling back to metadata on the
 * organization's own units. When nothing is configured the request still moves
 * to PendingImplementation with only the key set, so any user holding the
 * implement permission for that organization can pick it up.
 */
final class ImplementingUnitResolver
{
    /** @return array{key: string, unit_id: string|null} */
    public function resolve(OrganizationalChangeRequest $request): array
    {
        $key = $request->request_type->implementingUnitKey();

        return [
            'key' => $key,
            'unit_id' => $this->resolveUnitId($request->organization_id, $key),
        ];
    }

    public function keyForType(OrganizationalChangeRequestType $type): string
    {
        return $type->implementingUnitKey();
    }

    /**
     * Find the unit configured to implement this key for this organization.
     *
     * Resolution order:
     *  1. config('organizational_change.implementing_units.<org>.<key>')
     *  2. config('organizational_change.implementing_units.default.<key>')
     *  3. an organization unit whose metadata marks it with this key
     */
    private function resolveUnitId(string $organizationId, string $key): ?string
    {
        $configured = config("organizational_change.implementing_units.{$organizationId}.{$key}")
            ?? config("organizational_change.implementing_units.default.{$key}");

        if (is_string($configured) && $configured !== '') {
            $exists = OrganizationUnit::query()
                ->whereKey($configured)
                ->where('organization_id', $organizationId)
                ->exists();

            if ($exists) {
                return $configured;
            }
        }

        return $this->unitTaggedWithKey($organizationId, $key);
    }

    /**
     * Units can opt in by carrying {"implementing_unit_key": "..."} in their
     * metadata, which keeps the mapping in the data rather than in code.
     */
    private function unitTaggedWithKey(string $organizationId, string $key): ?string
    {
        $candidates = OrganizationUnit::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('metadata')
            ->get(['id', 'metadata']);

        foreach ($candidates as $unit) {
            $metadata = $unit->metadata;

            if (is_array($metadata) && ($metadata['implementing_unit_key'] ?? null) === $key) {
                return (string) $unit->getKey();
            }
        }

        return null;
    }

    /**
     * May this user implement this request?
     *
     * The permission is necessary but not sufficient: when the request has
     * been assigned to a named user, only that user (or an unrestricted
     * administrator) may apply it.
     */
    public function canImplement(User $user, OrganizationalChangeRequest $request, ChangeRequestScopeService $scope): bool
    {
        if (! $user->can('organizational-change-requests.implement')) {
            return false;
        }

        if (! $scope->canAccessOrganization($user, $request->organization_id) && ! $scope->isUnrestricted($user)) {
            return false;
        }

        $assignee = $request->implementation_assigned_to;

        if ($assignee !== null && (int) $assignee !== (int) $user->getKey()) {
            return $scope->isUnrestricted($user);
        }

        return true;
    }
}
