<?php

declare(strict_types=1);

namespace App\Services\RecycleBin;

use App\Models\CodeRule;
use App\Models\EntitlementRule;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\OrganizationType;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\ServiceType;
use Illuminate\Database\Eloquent\Model;

class RecycleBinRegistry
{
    /**
     * @return array<string, array{model: class-string<Model>, label_key: string, restore_permission: string, view_deleted_permission: string}>
     */
    public function supportedTypes(): array
    {
        return [
            'organizations' => [
                'model' => Organization::class,
                'label_key' => 'recycleBin.types.organizations',
                'restore_permission' => 'organizations.manage',
                'view_deleted_permission' => 'organizations.manage',
            ],
            'organization_types' => [
                'model' => OrganizationType::class,
                'label_key' => 'recycleBin.types.organization_types',
                'restore_permission' => 'organization-types.restore',
                'view_deleted_permission' => 'organization-types.viewDeleted',
            ],
            'organization_units' => [
                'model' => OrganizationUnit::class,
                'label_key' => 'recycleBin.types.organization_units',
                'restore_permission' => 'organization-units.restore',
                'view_deleted_permission' => 'organization-units.viewDeleted',
            ],
            'organization_unit_types' => [
                'model' => OrganizationUnitType::class,
                'label_key' => 'recycleBin.types.organization_unit_types',
                'restore_permission' => 'organization-unit-types.restore',
                'view_deleted_permission' => 'organization-unit-types.viewDeleted',
            ],
            'positions' => [
                'model' => Position::class,
                'label_key' => 'recycleBin.types.positions',
                'restore_permission' => 'positions.restore',
                'view_deleted_permission' => 'positions.viewDeleted',
            ],
            'service_types' => [
                'model' => ServiceType::class,
                'label_key' => 'recycleBin.types.service_types',
                'restore_permission' => 'service-types.restore',
                'view_deleted_permission' => 'service-types.viewDeleted',
            ],
            'entitlement_rules' => [
                'model' => EntitlementRule::class,
                'label_key' => 'recycleBin.types.entitlement_rules',
                'restore_permission' => 'entitlement-rules.restore',
                'view_deleted_permission' => 'entitlement-rules.viewDeleted',
            ],
            'code_rules' => [
                'model' => CodeRule::class,
                'label_key' => 'recycleBin.types.code_rules',
                'restore_permission' => 'code-rules.restore',
                'view_deleted_permission' => 'code-rules.viewDeleted',
            ],
        ];
    }

    /**
     * @return array{model: class-string<Model>, label_key: string, restore_permission: string, view_deleted_permission: string}
     */
    public function definition(string $type): array
    {
        $definitions = $this->supportedTypes();

        abort_unless(isset($definitions[$type]), 404);

        return $definitions[$type];
    }
}
