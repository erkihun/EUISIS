<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Services\Organizations\OrganizationDuplicateNameFinder;
use Illuminate\Validation\Validator;

/**
 * Where a unit sits in its organization's tree, shared by the create and edit
 * requests: an inactive unit type is not taken up by a unit that does not
 * already have it, and two units under the same parent never share a name
 * (compared without case and with runs of spaces collapsed).
 */
trait ValidatesOrganizationUnitPlacement
{
    protected function rejectInactiveUnitType(Validator $validator, ?string $currentTypeId = null): void
    {
        $typeId = $this->string('organization_unit_type_id')->toString();

        if ($typeId === '' || $typeId === $currentTypeId || $validator->errors()->has('organization_unit_type_id')) {
            return;
        }

        if (OrganizationUnitType::query()->whereKey($typeId)->where('is_active', false)->exists()) {
            $validator->errors()->add('organization_unit_type_id', __('organization-units.inactive_unit_type'));
        }
    }

    protected function rejectDuplicateSiblingNames(Validator $validator, ?string $organizationId, ?string $ignoreUnitId = null): void
    {
        if ($organizationId === null || $organizationId === '') {
            return;
        }

        $parentId = $this->input('parent_unit_id') ?: null;

        foreach (['name_en', 'name_am'] as $field) {
            $name = OrganizationDuplicateNameFinder::normalize($this->input($field));

            if ($name === null || $validator->errors()->has($field)) {
                continue;
            }

            $sibling = OrganizationUnit::query()
                ->where('organization_id', $organizationId)
                ->when(
                    $parentId === null,
                    fn ($query) => $query->whereNull('parent_unit_id'),
                    fn ($query) => $query->where('parent_unit_id', $parentId),
                )
                ->when($ignoreUnitId !== null, fn ($query) => $query->whereKeyNot($ignoreUnitId))
                ->whereRaw("LOWER(TRIM({$field})) = ?", [mb_strtolower($name, 'UTF-8')])
                ->first(['id', 'code']);

            if ($sibling !== null) {
                $validator->errors()->add($field, __('organization-units.duplicate_sibling_name', ['code' => (string) $sibling->code]));
            }
        }
    }
}
