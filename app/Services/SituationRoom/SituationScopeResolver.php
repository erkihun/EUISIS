<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Models\OrganizationClosurePath;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Turns a viewer and an optional focus organization into a SituationScope.
 *
 * Authority comes only from OrganizationScopeService. A focus organization the
 * viewer may not see is refused (403): a forged `organization_id` never widens
 * or shifts what is counted.
 */
final class SituationScopeResolver
{
    public function __construct(private readonly OrganizationScopeService $scope) {}

    /** @throws AuthorizationException */
    public function resolve(User $user, ?string $focusOrganizationId = null): SituationScope
    {
        $unrestricted = $this->scope->isUnrestricted($user);
        $allowed = $unrestricted ? null : $this->scope->accessibleOrganizationIds($user)->map(fn ($id): string => (string) $id)->unique()->values()->all();

        if ($focusOrganizationId === null || $focusOrganizationId === '') {
            return new SituationScope($allowed, null, $unrestricted);
        }

        if ($allowed !== null && ! in_array($focusOrganizationId, $allowed, true)) {
            throw new AuthorizationException('The organization is outside your scope.');
        }

        $subtree = $this->subtree($focusOrganizationId);
        $ids = $allowed === null ? $subtree : array_values(array_intersect($subtree, $allowed));

        return new SituationScope($ids, $focusOrganizationId, $unrestricted);
    }

    /**
     * The organization and its descendants on the published hierarchy, the
     * relation OrganizationScopeService treats as authoritative for subtree
     * access. No published version: the organization alone.
     *
     * @return list<string>
     */
    public function subtree(string $organizationId): array
    {
        $versionId = $this->publishedVersionId();
        $ids = [$organizationId];

        if ($versionId !== null) {
            $ids = array_merge($ids, OrganizationClosurePath::query()
                ->where('hierarchy_version_id', $versionId)
                ->where('ancestor_organization_id', $organizationId)
                ->pluck('descendant_organization_id')
                ->map(fn ($id): string => (string) $id)
                ->all());
        }

        return array_values(array_unique($ids));
    }

    /**
     * Direct children of an organization on the published hierarchy, for
     * drill-down from a parent to its next level.
     *
     * @return list<string>
     */
    public function children(string $organizationId): array
    {
        $versionId = $this->publishedVersionId();

        if ($versionId === null) {
            return [];
        }

        return OrganizationClosurePath::query()
            ->where('hierarchy_version_id', $versionId)
            ->where('ancestor_organization_id', $organizationId)
            ->where('depth', 1)
            ->pluck('descendant_organization_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    public function publishedVersionId(): ?string
    {
        return app(SituationMemo::class)->remember('published_version', fn (): ?string => $this->lookUpPublishedVersion());
    }

    private function lookUpPublishedVersion(): ?string
    {
        $id = HierarchyVersion::query()
            ->where('status', HierarchyVersionStatus::Published->value)
            ->latest('approval_date')
            ->value('id');

        return $id === null ? null : (string) $id;
    }
}
