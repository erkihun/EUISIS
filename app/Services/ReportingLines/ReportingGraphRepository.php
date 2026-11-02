<?php

declare(strict_types=1);

namespace App\Services\ReportingLines;

use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationUnitStatus;
use App\Models\HierarchyVersion;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionProfile;
use Illuminate\Support\Carbon;

/**
 * Builds {@see PositionReportingGraph}s from the database, one per
 * organization and date, and keeps them for the rest of the request. Nothing
 * is cached across requests, so a change to a position, unit, profile or
 * hierarchy version is seen by the next request without invalidation.
 */
class ReportingGraphRepository
{
    /** @var array<string, PositionReportingGraph> */
    private array $graphs = [];

    /** @var array<string, ParentOrganizationLink> */
    private array $parents = [];

    /** @var array<string, list<string>> organization id => child organization ids (per date) */
    private array $children = [];

    private ?string $publishedVersionId = null;

    private bool $publishedResolved = false;

    public function forOrganization(string $organizationId, Carbon|string|null $asOf = null): PositionReportingGraph
    {
        $day = self::day($asOf);
        $key = $organizationId.'|'.$day;

        return $this->graphs[$key] ??= $this->build($organizationId, $day);
    }

    public function forget(): void
    {
        $this->graphs = [];
        $this->parents = [];
        $this->children = [];
        $this->publishedVersionId = null;
        $this->publishedResolved = false;
    }

    public static function day(Carbon|string|null $asOf): string
    {
        if ($asOf === null) {
            return today()->toDateString();
        }

        return $asOf instanceof Carbon ? $asOf->toDateString() : Carbon::parse($asOf)->toDateString();
    }

    /**
     * The organizations directly under the given one in the published
     * hierarchy on the date (for an organization head's direct reports).
     *
     * @return list<string>
     */
    public function childOrganizationIds(string $organizationId, Carbon|string|null $asOf = null): array
    {
        $day = self::day($asOf);
        $key = $organizationId.'|'.$day;
        if (isset($this->children[$key])) {
            return $this->children[$key];
        }
        $versionId = $this->publishedVersionId();
        if ($versionId === null) {
            return $this->children[$key] = [];
        }

        return $this->children[$key] = OrganizationEdge::query()
            ->where('hierarchy_version_id', $versionId)
            ->where('parent_organization_id', $organizationId)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->pluck('child_organization_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function build(string $organizationId, string $day): PositionReportingGraph
    {
        $positions = Position::query()
            ->where('organization_id', $organizationId)
            ->get(['id', 'organization_id', 'organization_unit_id', 'is_unit_head', 'is_active', 'effective_from', 'effective_to', 'title_en', 'title_am', 'job_position_code']);

        // The profile in force per position: published or superseded, covering the day, highest version.
        $explicit = [];
        if ($positions->isNotEmpty()) {
            $profiles = PositionProfile::query()
                ->whereIn('position_id', $positions->pluck('id'))
                ->whereIn('status', [OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value])
                ->whereDate('effective_from', '<=', $day)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
                ->orderBy('position_id')
                ->orderByDesc('version_no')
                ->get(['position_id', 'reports_to_position_id', 'version_no']);
            foreach ($profiles as $profile) {
                $positionId = (string) $profile->position_id;
                if (array_key_exists($positionId, $explicit)) {
                    continue; // a lower version of the same lineage
                }
                $explicit[$positionId] = $profile->reports_to_position_id === null ? null : (string) $profile->reports_to_position_id;
            }
        }

        $nodes = [];
        foreach ($positions as $position) {
            $nodes[(string) $position->id] = new PositionNode(
                id: (string) $position->id,
                organizationId: (string) $position->organization_id,
                unitId: $position->organization_unit_id === null ? null : (string) $position->organization_unit_id,
                isUnitHead: (bool) $position->is_unit_head,
                isActiveOnDate: $position->isSelectable(Carbon::parse($day)),
                titleEn: (string) ($position->title_en ?: $position->title_am ?: $position->job_position_code),
                titleAm: $position->title_am,
                jobPositionCode: $position->job_position_code,
                explicitReportsTo: $explicit[(string) $position->id] ?? null,
            );
        }

        $units = [];
        foreach (OrganizationUnit::query()->where('organization_id', $organizationId)->get(['id', 'organization_id', 'parent_unit_id', 'status', 'effective_from', 'effective_to', 'name_en', 'name_am', 'code']) as $unit) {
            $active = $unit->status === OrganizationUnitStatus::Active
                || (is_string($unit->status) && $unit->status === OrganizationUnitStatus::Active->value);
            if ($active && $unit->effective_from !== null && $unit->effective_from->toDateString() > $day) {
                $active = false;
            }
            if ($active && $unit->effective_to !== null && $unit->effective_to->toDateString() < $day) {
                $active = false;
            }
            $units[(string) $unit->id] = new UnitNode(
                id: (string) $unit->id,
                organizationId: (string) $unit->organization_id,
                parentId: $unit->parent_unit_id === null ? null : (string) $unit->parent_unit_id,
                isActive: $active,
                nameEn: (string) ($unit->name_en ?: $unit->name_am ?: $unit->code),
                nameAm: $unit->name_am,
                code: $unit->code,
            );
        }

        return new PositionReportingGraph(
            organizationId: $organizationId,
            asOf: $day,
            positions: $nodes,
            units: $units,
            parentOrganization: fn (): ParentOrganizationLink => $this->parentOf($organizationId, $day),
        );
    }

    private function parentOf(string $organizationId, string $day): ParentOrganizationLink
    {
        $key = $organizationId.'|'.$day;
        if (isset($this->parents[$key])) {
            return $this->parents[$key];
        }

        $versionId = $this->publishedVersionId();
        if ($versionId === null) {
            return $this->parents[$key] = ParentOrganizationLink::none();
        }

        $parentIds = OrganizationEdge::query()
            ->where('hierarchy_version_id', $versionId)
            ->where('child_organization_id', $organizationId)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->pluck('parent_organization_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values();

        if ($parentIds->isEmpty()) {
            return $this->parents[$key] = ParentOrganizationLink::none();
        }
        if ($parentIds->count() > 1) {
            return $this->parents[$key] = new ParentOrganizationLink(true, true, null, null, null, null);
        }

        $parentId = (string) $parentIds->first();
        $parent = Organization::query()->whereKey($parentId)->first(['id', 'name_en', 'name_am']);
        [$head, $several] = $this->forOrganization($parentId, $day)->organizationHead();

        return $this->parents[$key] = new ParentOrganizationLink(
            exists: true,
            ambiguous: false,
            organizationId: $parentId,
            organizationNameEn: $parent?->name_en,
            organizationNameAm: $parent?->name_am,
            headPosition: $head,
            headAmbiguous: $several,
        );
    }

    private function publishedVersionId(): ?string
    {
        if (! $this->publishedResolved) {
            $value = HierarchyVersion::query()
                ->where('status', HierarchyVersionStatus::Published->value)
                ->latest('approval_date')
                ->value('id');
            $this->publishedVersionId = $value === null ? null : (string) $value;
            $this->publishedResolved = true;
        }

        return $this->publishedVersionId;
    }
}
