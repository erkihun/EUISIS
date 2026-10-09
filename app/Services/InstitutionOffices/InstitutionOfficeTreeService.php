<?php

declare(strict_types=1);

namespace App\Services\InstitutionOffices;

use App\Models\InstitutionOffice;
use App\Models\Organization;
use Illuminate\Support\Collection;

class InstitutionOfficeTreeService
{
    /**
     * Build a flat-to-tree collection of offices for an institution.
     */
    public function getTreeForInstitution(Organization $institution): Collection
    {
        $offices = InstitutionOffice::query()
            ->forInstitution($institution->id)
            ->orderBy('name_en')
            ->get();

        return $this->buildTree($offices, null);
    }

    /**
     * Get all descendant office IDs for a given office.
     *
     * @return string[]
     */
    public function getDescendantOfficeIds(InstitutionOffice $office): array
    {
        $ids = [];
        $this->collectDescendantIds($office->id, $office->institution_id, $ids);

        return $ids;
    }

    /**
     * Get all ancestor office IDs for a given office (bottom-up).
     *
     * @return string[]
     */
    public function getAncestorOfficeIds(InstitutionOffice $office): array
    {
        $ids = [];
        $current = $office->parentOffice;

        while ($current !== null) {
            $ids[] = $current->id;
            $current = $current->parentOffice;
        }

        return $ids;
    }

    /**
     * Determine if $parent can be the parent of $child without creating a cycle.
     */
    public function canBeParent(InstitutionOffice $parent, InstitutionOffice $child): bool
    {
        if ($parent->id === $child->id) {
            return false;
        }

        if ($parent->institution_id !== $child->institution_id) {
            return false;
        }

        // Ensure $parent is not a descendant of $child
        $descendantIds = $this->getDescendantOfficeIds($child);

        return ! in_array($parent->id, $descendantIds, true);
    }

    /**
     * Validate that the office hierarchy has no cycles and correct institution context.
     */
    public function validateOfficeHierarchy(InstitutionOffice $office): bool
    {
        if ($office->parent_office_id === null) {
            return true;
        }

        if ($office->parent_office_id === $office->id) {
            return false;
        }

        $parent = InstitutionOffice::withTrashed()->find($office->parent_office_id);

        if ($parent === null) {
            return false;
        }

        if ($parent->institution_id !== $office->institution_id) {
            return false;
        }

        $ancestors = $this->getAncestorOfficeIds($office);

        return ! in_array($office->id, $ancestors, true);
    }

    /**
     * Build a nested tree from a flat collection.
     */
    private function buildTree(Collection $offices, ?string $parentId): Collection
    {
        return $offices
            ->filter(fn (InstitutionOffice $o) => $o->parent_office_id === $parentId)
            ->map(function (InstitutionOffice $office) use ($offices): array {
                return [
                    'id' => $office->id,
                    'office_code' => $office->office_code,
                    'name_en' => $office->name_en,
                    'name_am' => $office->name_am,
                    'office_level' => $office->office_level->value,
                    'status' => $office->status->value,
                    'is_head_office' => $office->is_head_office,
                    'parent_office_id' => $office->parent_office_id,
                    'children' => $this->buildTree($offices, $office->id)->values()->all(),
                ];
            })
            ->values();
    }

    private function collectDescendantIds(string $officeId, string $institutionId, array &$ids): void
    {
        $children = InstitutionOffice::query()
            ->forInstitution($institutionId)
            ->where('parent_office_id', $officeId)
            ->pluck('id');

        foreach ($children as $childId) {
            $ids[] = $childId;
            $this->collectDescendantIds($childId, $institutionId, $ids);
        }
    }
}
