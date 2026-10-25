<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CoreAuditLog;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The governed hierarchy lifecycle: a draft copied from the structure in force,
 * parent changes inside the draft, review, approval by a second city-wide
 * administrator, and publication on its effective date (the version in force
 * becomes archived and stays queryable; history is never rewritten). Each step
 * records the impact on organizations, units, positions, placements and scoped
 * accounts. A review or approval can be sent back to draft. The approver is a
 * city-wide administrator who did not prepare, change or submit the version.
 *
 * The immediate parent change of the organization form is kept for daily work:
 * it publishes a snapshot of the structure with the change, once per day.
 */
final readonly class ManageHierarchyVersion
{
    /** Marks the snapshots the immediate organization edits publish. */
    public const IMMEDIATE_NOTE = 'Historical snapshot for the existing immediate organization editing workflow; no review approval recorded.';

    public const TRANSITIONS = ['review', 'approve', 'publish', 'reject'];

    public function __construct(private AuditWriter $audit, private OrganizationScope $scope) {}

    public function create(array $data, User $actor): HierarchyVersion
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($data, $actor): HierarchyVersion {
            $this->lockVersions();
            $base = HierarchyVersion::current();
            $version = HierarchyVersion::query()->create($data + [
                'status' => 'draft', 'created_by' => $actor->id, 'base_version_id' => $base?->id,
            ]);
            if ($base !== null) {
                $this->copyEdges($base, $version);
            }
            $this->audit->write('hierarchy.created', $actor, $version, null, null, $version->only(['version_name', 'base_version_id', 'status']));

            return $version;
        }, 3);
    }

    /** The draft's own details (name, effective date, legal basis, source, notes); its relationships change with changeParent. */
    public function updateDraft(HierarchyVersion $version, array $data, User $actor): HierarchyVersion
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($version, $data, $actor): HierarchyVersion {
            $this->lockVersions();
            $version = HierarchyVersion::query()->findOrFail($version->id);
            $this->requireStatus($version, 'draft');
            $fields = array_keys($data);
            $old = $version->only($fields);
            $version->fill($data)->save();
            $this->audit->write('hierarchy.updated', $actor, $version, null, $old, $version->only($fields));

            return $version;
        }, 3);
    }

    public function changeParent(HierarchyVersion $version, string $childId, ?string $parentId, User $actor): HierarchyVersion
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($version, $childId, $parentId, $actor): HierarchyVersion {
            $this->lockVersions();
            $version = HierarchyVersion::query()->findOrFail($version->id);
            $this->requireStatus($version, 'draft');
            if (! Organization::query()->whereKey($childId)->exists() || ($parentId !== null && ! Organization::query()->whereKey($parentId)->exists())) {
                throw ValidationException::withMessages(['parent_organization_id' => 'Both organizations must exist.']);
            }
            OrganizationEdge::query()->where('hierarchy_version_id', $version->id)
                ->whereIn('relationship_type', OrganizationEdge::TREE_TYPES)->where('child_organization_id', $childId)->delete();
            if ($parentId !== null) {
                OrganizationEdge::query()->create([
                    'hierarchy_version_id' => $version->id, 'parent_organization_id' => $parentId,
                    'child_organization_id' => $childId, 'relationship_type' => OrganizationEdge::REPORTS_TO,
                    'effective_from' => $version->effective_from,
                ]);
            }
            $this->validateTree($version);
            $this->audit->write('hierarchy.parent_changed', $actor, $version, $childId, null, ['parent_organization_id' => $parentId]);

            return $version;
        }, 3);
    }

    public function transition(HierarchyVersion $version, string $transition, User $actor): HierarchyVersion
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($version, $transition, $actor): HierarchyVersion {
            $this->lockVersions();
            $version = HierarchyVersion::query()->findOrFail($version->id);
            $from = $version->status;
            $this->validateTree($version);
            if ($transition === 'review') {
                $this->requireStatus($version, 'draft');
                $version->fill(['status' => 'review', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            } elseif ($transition === 'approve') {
                $this->requireStatus($version, 'review');
                if (($refusal = $this->approvalRefusal($version, $actor)) !== null) {
                    throw ValidationException::withMessages(['status' => $refusal]);
                }
                $version->fill(['status' => 'approved', 'approved_by' => $actor->id, 'approval_date' => now()]);
            } elseif ($transition === 'publish') {
                $this->requireStatus($version, 'approved');
                if (($refusal = $this->publicationRefusal($version)) !== null) {
                    throw ValidationException::withMessages([$refusal[0] => $refusal[1]]);
                }
                $this->archivePublished();
                $version->status = HierarchyVersion::PUBLISHED;
            } elseif ($transition === 'reject') {
                if (! in_array($version->status, ['review', 'approved'], true)) {
                    throw ValidationException::withMessages(['status' => 'Only a version in review or approved can be sent back to draft.']);
                }
                $version->fill(['status' => 'draft', 'reviewed_by' => null, 'reviewed_at' => null, 'approved_by' => null, 'approval_date' => null]);
            } else {
                throw ValidationException::withMessages(['status' => 'Unknown hierarchy transition.']);
            }
            $version->save();
            $this->scope->clearCache();
            $this->audit->write('hierarchy.'.$transition, $actor, $version, null, ['status' => $from], ['status' => $version->status, 'impact' => $this->impact($version)]);

            return $version;
        }, 3);
    }

    /** Compatibility path: snapshot immediate structure edits without inventing approval. */
    public function forkPublished(User $actor): ?HierarchyVersion
    {
        $this->lockVersions();
        $base = HierarchyVersion::current();
        if ($base === null) {
            return null;
        }
        // Today's snapshot already holds today's earlier edits; earlier days stay as they were.
        if ($base->notes === self::IMMEDIATE_NOTE && $base->effective_from?->isToday()) {
            return $base;
        }
        $next = HierarchyVersion::query()->create([
            'version_name' => 'Structure '.now()->format('Y-m-d').' '.substr((string) Str::uuid7(), -8), 'status' => HierarchyVersion::PUBLISHED,
            'effective_from' => now()->toDateString(), 'base_version_id' => $base->id,
            'created_by' => $actor->id, 'notes' => self::IMMEDIATE_NOTE,
        ]);
        $this->copyEdges($base, $next);
        HierarchyVersion::query()->where('status', HierarchyVersion::PUBLISHED)->whereKeyNot($next->id)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', today()))
            ->update(['status' => 'archived', 'effective_to' => now()->toDateString()]);
        $this->scope->clearCache();
        $this->audit->write('hierarchy.snapshotted', $actor, $next, null, ['version_id' => $base->id], ['version_id' => $next->id]);

        return $next;
    }

    public function impact(HierarchyVersion $version): array
    {
        $parents = fn (?string $id) => $id === null ? collect() : OrganizationEdge::query()
            ->where('hierarchy_version_id', $id)->whereIn('relationship_type', OrganizationEdge::TREE_TYPES)
            ->pluck('parent_organization_id', 'child_organization_id');
        $old = $parents($version->base_version_id);
        $new = $parents($version->id);
        $changed = $old->keys()->merge($new->keys())->unique()->filter(fn ($id) => $old->get($id) !== $new->get($id))->values()->all();
        // Descendants inherit the changed hierarchy and must be included in impact.
        do {
            $count = count($changed);
            foreach ([$old, $new] as $tree) {
                foreach ($tree as $child => $parent) {
                    if (in_array($parent, $changed, true) && ! in_array($child, $changed, true)) {
                        $changed[] = $child;
                    }
                }
            }
        } while (count($changed) !== $count);

        return [
            'organizations' => count($changed),
            'units' => OrganizationUnit::query()->whereIn('organization_id', $changed)->count(),
            'positions' => Position::query()->whereIn('organization_id', $changed)->count(),
            'employees' => EmployeeAssignment::query()->whereIn('organization_id', $changed)->where('is_current', true)->distinct()->count('employee_id'),
            'assignments' => EmployeeAssignment::query()->whereIn('organization_id', $changed)->count(),
            'users' => User::query()->whereIn('scope_organization_id', $changed)->count(),
        ];
    }

    /** Why this administrator may not approve the version (separation of duties), or null; the screens show the same answer. */
    public function approvalRefusal(HierarchyVersion $version, User $actor): ?string
    {
        $prepared = (int) $version->created_by === (int) $actor->id || (int) $version->reviewed_by === (int) $actor->id
            || CoreAuditLog::query()->where('subject_type', class_basename($version))->where('subject_id', (string) $version->id)
                ->whereIn('action', ['hierarchy.parent_changed', 'hierarchy.updated'])->where('actor_user_id', $actor->id)->exists();

        return $prepared ? 'A different administrator must approve the hierarchy: one who did not prepare, change or submit it.' : null;
    }

    /**
     * Why an approved version cannot be published now, as [field, message], or null.
     *
     * @return array{0: string, 1: string}|null
     */
    public function publicationRefusal(HierarchyVersion $version): ?array
    {
        if ($version->effective_from === null || ! $version->effective_from->isToday()) {
            return ['effective_from', 'Publish on the effective date; retrospective and scheduled publication are not supported.'];
        }
        if ((string) $version->base_version_id !== (string) HierarchyVersion::current()?->id) {
            return ['status', 'The published hierarchy changed. Create and review a new version from the current hierarchy.'];
        }

        return null;
    }

    /** Only a city-wide administrator governs the hierarchy. */
    public static function governs(?User $user): bool
    {
        return $user !== null && $user->is_active && $user->role === 'core_admin' && $user->isCityWide();
    }

    private function authorize(User $actor): void
    {
        abort_unless(self::governs($actor), 403);
    }

    private function lockVersions(): void
    {
        HierarchyVersion::query()->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function requireStatus(HierarchyVersion $version, string $status): void
    {
        if ($version->status !== $status) {
            throw ValidationException::withMessages(['status' => 'This transition requires a '.$status.' hierarchy.']);
        }
    }

    private function validateTree(HierarchyVersion $version): void
    {
        $parents = [];
        foreach (OrganizationEdge::query()->where('hierarchy_version_id', $version->id)->whereIn('relationship_type', OrganizationEdge::TREE_TYPES)->get() as $edge) {
            if (isset($parents[$edge->child_organization_id]) && $parents[$edge->child_organization_id] !== $edge->parent_organization_id) {
                throw ValidationException::withMessages(['parent_organization_id' => 'An organization cannot have multiple structural parents.']);
            }
            $parents[$edge->child_organization_id] = $edge->parent_organization_id;
        }
        foreach (array_keys($parents) as $child) {
            $seen = [];
            while (isset($parents[$child])) {
                if (isset($seen[$child])) {
                    throw ValidationException::withMessages(['parent_organization_id' => 'The hierarchy cannot contain a cycle.']);
                }
                $seen[$child] = true;
                $child = $parents[$child];
            }
        }
    }

    private function archivePublished(): void
    {
        HierarchyVersion::query()->where('status', HierarchyVersion::PUBLISHED)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', today()))
            ->update(['status' => 'archived', 'effective_to' => now()->toDateString()]);
    }

    private function copyEdges(HierarchyVersion $from, HierarchyVersion $to): void
    {
        OrganizationEdge::query()->where('hierarchy_version_id', $from->id)->chunkById(500, function ($edges) use ($to): void {
            $rows = $edges->map(fn ($edge) => [
                'id' => (string) Str::uuid7(), 'hierarchy_version_id' => $to->id,
                'parent_organization_id' => $edge->parent_organization_id, 'child_organization_id' => $edge->child_organization_id,
                'relationship_type' => $edge->relationship_type, 'effective_from' => $edge->getRawOriginal('effective_from'),
                'effective_to' => $edge->getRawOriginal('effective_to'), 'created_at' => now(), 'updated_at' => now(),
            ])->all();
            OrganizationEdge::query()->insert($rows);
        });
    }
}
