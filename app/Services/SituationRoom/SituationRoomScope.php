<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use App\Enums\HierarchyVersionStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Modules\Feedback\Contracts\FeedbackVisibility;

/**
 * Where a leader is looking: their authorized organizations, optionally
 * narrowed to one institution (the "node") and everything under it in the
 * published hierarchy (docs/situation-room-hierarchy-rollup.md).
 *
 * Roll-up uses the same organization_closure_paths of the published
 * hierarchy version that OrganizationScopeService uses for access, so what a
 * leader may see and what rolls up to them always agree. A submission is
 * placed by the organization recorded on it when it was submitted
 * (employee_service_feedback.organization_id) and counted once: totals are
 * filters over distinct rows, never sums over parents.
 */
final class SituationRoomScope
{
    /**
     * @param  list<string>|null  $allowedIds  null = unrestricted
     * @param  list<string>  $visibleTargets
     */
    private function __construct(
        public readonly User $user,
        public readonly ?Organization $node,
        public readonly ?string $versionId,
        public readonly ?array $allowedIds,
        public readonly array $visibleTargets,
    ) {}

    /** @throws AuthorizationException when the requested institution is outside the user's scope */
    public static function for(User $user, ?string $nodeId, OrganizationScopeService $scope, FeedbackVisibility $visibility): self
    {
        $unrestricted = $scope->isUnrestricted($user);
        $allowed = $unrestricted ? null : array_values(array_map('strval', $scope->accessibleOrganizationIds($user)->all()));

        $node = null;
        if ($nodeId !== null && $nodeId !== '') {
            $node = Organization::query()->find($nodeId);
            if ($node === null || ! ($unrestricted || in_array((string) $node->getKey(), $allowed, true))) {
                throw new AuthorizationException;
            }
        }

        $versionId = HierarchyVersion::query()
            ->where('status', HierarchyVersionStatus::Published->value)
            ->latest('approval_date')
            ->value('id');

        return new self($user, $node, $versionId !== null ? (string) $versionId : null, $allowed, $visibility->visibleTargetTypes($user));
    }

    public function isUnrestricted(): bool
    {
        return $this->allowedIds === null;
    }

    /**
     * Confine a submissions query to this scope.
     *
     * @template T of Builder|QueryBuilder
     *
     * @param  T  $query
     * @return T
     */
    public function apply(Builder|QueryBuilder $query, string $column = 'employee_service_feedback.organization_id'): Builder|QueryBuilder
    {
        if ($this->allowedIds !== null) {
            $this->allowedIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn($column, $this->allowedIds);
        }

        if ($this->node !== null) {
            $nodeId = (string) $this->node->getKey();
            $query->where(function ($q) use ($column, $nodeId): void {
                $q->where($column, $nodeId);
                if ($this->versionId !== null) {
                    $q->orWhereIn($column, DB::table('organization_closure_paths')
                        ->select('descendant_organization_id')
                        ->where('hierarchy_version_id', $this->versionId)
                        ->where('ancestor_organization_id', $nodeId));
                }
            });
        }

        return $query;
    }

    /**
     * The institutions compared side by side: the node's direct children, or
     * — at the top of a scope — the highest institutions the user may see.
     *
     * @return list<string>
     */
    public function childIds(): array
    {
        if ($this->versionId === null) {
            return [];
        }

        if ($this->node !== null) {
            $ids = DB::table('organization_closure_paths')
                ->where('hierarchy_version_id', $this->versionId)
                ->where('ancestor_organization_id', (string) $this->node->getKey())
                ->where('depth', 1)
                ->pluck('descendant_organization_id')
                ->map(fn ($id): string => (string) $id)
                ->all();

            return array_values($this->allowedIds === null ? $ids : array_intersect($ids, $this->allowedIds));
        }

        if ($this->allowedIds === null) {
            // Top of the published structure: never anyone's descendant.
            return DB::table('organization_closure_paths as self')
                ->where('self.hierarchy_version_id', $this->versionId)
                ->where('self.depth', 0)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('organization_closure_paths as up')
                    ->whereColumn('up.descendant_organization_id', 'self.descendant_organization_id')
                    ->where('up.hierarchy_version_id', $this->versionId)
                    ->where('up.depth', '>', 0))
                ->pluck('self.descendant_organization_id')
                ->map(fn ($id): string => (string) $id)
                ->all();
        }

        // Top of a scoped user's tree: allowed institutions with no allowed ancestor.
        $covered = DB::table('organization_closure_paths')
            ->where('hierarchy_version_id', $this->versionId)
            ->where('depth', '>', 0)
            ->whereIn('ancestor_organization_id', $this->allowedIds)
            ->whereIn('descendant_organization_id', $this->allowedIds)
            ->pluck('descendant_organization_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        return array_values(array_diff($this->allowedIds, $covered));
    }

    /**
     * The node's ancestors the user may open, top first, for the breadcrumb.
     *
     * @return list<array{id: string, name_en: string, name_am: ?string}>
     */
    public function breadcrumb(): array
    {
        if ($this->node === null) {
            return [];
        }

        $ancestorIds = $this->versionId === null ? [] : DB::table('organization_closure_paths')
            ->where('hierarchy_version_id', $this->versionId)
            ->where('descendant_organization_id', (string) $this->node->getKey())
            ->where('depth', '>', 0)
            ->orderByDesc('depth')
            ->pluck('ancestor_organization_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($this->allowedIds !== null) {
            $ancestorIds = array_values(array_intersect($ancestorIds, $this->allowedIds));
        }

        $names = Organization::query()->whereIn('id', $ancestorIds)->get(['id', 'name_en', 'name_am'])->keyBy('id');

        $trail = [];
        foreach ([...$ancestorIds, (string) $this->node->getKey()] as $id) {
            $organization = $id === (string) $this->node->getKey() ? $this->node : ($names[$id] ?? null);
            if ($organization !== null) {
                $trail[] = ['id' => (string) $organization->getKey(), 'name_en' => (string) $organization->name_en, 'name_am' => $organization->name_am];
            }
        }

        return $trail;
    }

    /** Everything a cached figure depends on: never shared across scopes, grants or definitions. */
    public function signature(): string
    {
        return sha1(implode('|', [
            SituationRoomMetrics::VERSION,
            $this->versionId ?? 'none',
            $this->node?->getKey() ?? 'top',
            $this->allowedIds === null ? 'all' : sha1(implode(',', $this->sortedAllowedIds())),
            implode(',', $this->visibleTargets),
            // The user's own submissions are excluded, so the figures are per employee.
            (string) ($this->user->employee_id ?? 'none'),
        ]));
    }

    /** @return list<string> */
    private function sortedAllowedIds(): array
    {
        $ids = $this->allowedIds ?? [];
        sort($ids);

        return $ids;
    }
}
