<?php

declare(strict_types=1);

namespace App\Actions\OrganizationUnits;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\OrganizationUnits\OrganizationUnitStructureCopyService;
use Illuminate\Support\Facades\DB;

readonly class CopyOrganizationUnitStructureAction
{
    public function __construct(
        private OrganizationUnitStructureCopyService $copyService,
        private WriteAuditLogAction $writeAuditLogAction,
    ) {}

    /**
     * Execute the structure copy inside a transaction and write an audit log.
     *
     * @param  array{
     *     source_organization_id: string,
     *     source_unit_id: string|null,
     *     target_organization_id: string,
     *     target_parent_unit_id: string|null,
     *     copy_positions: bool,
     *     copy_functional_relationships: bool,
     *     name_prefix: string|null,
     *     name_suffix: string|null,
     *     status: string,
     *     effective_from: string|null,
     * }  $data
     */
    public function execute(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor): array {
            $sourceOrgId = $data['source_organization_id'];
            $targetOrgId = $data['target_organization_id'];

            // Determine which units to copy
            if (! empty($data['source_unit_id'])) {
                $sourceUnits = OrganizationUnit::query()
                    ->where('id', $data['source_unit_id'])
                    ->where('organization_id', $sourceOrgId)
                    ->whereNull('deleted_at')
                    ->get();
            } else {
                // Copy all root units of the source org
                $sourceUnits = OrganizationUnit::query()
                    ->where('organization_id', $sourceOrgId)
                    ->whereNull('parent_unit_id')
                    ->whereNull('deleted_at')
                    ->orderBy('sort_order')
                    ->get();
            }

            $options = [
                'copy_positions' => (bool) ($data['copy_positions'] ?? false),
                'copy_functional_relationships' => (bool) ($data['copy_functional_relationships'] ?? false),
                'name_prefix' => $data['name_prefix'] ?? null,
                'name_suffix' => $data['name_suffix'] ?? null,
                'status' => $data['status'],
                'effective_from' => $data['effective_from'] ?? null,
            ];

            $result = $this->copyService->copyTree(
                $sourceUnits,
                $targetOrgId,
                $data['target_parent_unit_id'] ?? null,
                $options,
                $actor,
            );

            $this->writeAuditLogAction->execute(
                AuditEventType::OrganizationUnitStructureCopied,
                $actor,
                null,
                $targetOrgId,
                newValues: [
                    'source_organization_id' => $sourceOrgId,
                    'source_unit_id' => $data['source_unit_id'] ?? null,
                    'target_organization_id' => $targetOrgId,
                    'target_parent_unit_id' => $data['target_parent_unit_id'] ?? null,
                    'units_copied' => $result['units'],
                    'positions_copied' => $result['positions'],
                    'copy_positions' => $options['copy_positions'],
                    'status' => $options['status'],
                ],
            );

            return $result;
        });
    }
}
