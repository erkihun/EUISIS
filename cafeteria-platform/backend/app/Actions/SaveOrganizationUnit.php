<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitType;
use App\Models\Position;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CodeGenerator;
use App\Services\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes an organization unit (EUISIS Create/UpdateOrganizationUnitAction):
 * inside the actor's scope, its parent unit in the same organization and never
 * below itself, its code unique within the organization (generated when blank).
 * A unit that still has active positions cannot be switched off.
 */
final readonly class SaveOrganizationUnit
{
    public const STATUSES = ['draft', 'active', 'inactive', 'archived'];

    /** EUISIS OrganizationUnitType enum: the unit_type column keeps one of these. */
    private const KINDS = ['department', 'directorate', 'team', 'unit', 'office', 'section'];

    public function __construct(private AuditWriter $audit, private OrganizationScope $scope, private CodeGenerator $codes) {}

    /** @param array<string, mixed> $data organization_id, organization_unit_type_id, parent_unit_id, code, name_en, name_am, status */
    public function execute(?OrganizationUnit $unit, array $data, User $actor): OrganizationUnit
    {
        return DB::transaction(function () use ($unit, $data, $actor): OrganizationUnit {
            $organizationId = (string) ($data['organization_id'] ?? $unit?->organization_id);
            if (! $this->scope->allows($actor, $organizationId) || ($unit !== null && ! $this->scope->allows($actor, (string) $unit->organization_id))) {
                throw ValidationException::withMessages(['organization_id' => 'The organization is outside your organizations.']);
            }
            if ($unit !== null && isset($data['organization_id']) && $data['organization_id'] !== $unit->organization_id) {
                throw ValidationException::withMessages(['organization_id' => 'A unit stays in its organization; create a new unit instead.']);
            }

            $parentId = $data['parent_unit_id'] ?? null;
            if ($parentId !== null) {
                $parent = OrganizationUnit::query()->find($parentId);
                if ($parent === null || (string) $parent->organization_id !== $organizationId) {
                    throw ValidationException::withMessages(['parent_unit_id' => 'The parent unit must belong to the same organization.']);
                }
                if ($unit !== null && in_array((string) $parentId, $this->descendants($unit), true)) {
                    throw ValidationException::withMessages(['parent_unit_id' => 'A unit cannot sit under itself or one of its own units.']);
                }
            }

            if (isset($data['organization_unit_type_id'])) {
                $code = strtolower((string) OrganizationUnitType::query()->whereKey($data['organization_unit_type_id'])->value('code'));
                $data['unit_type'] = in_array($code, self::KINDS, true) ? $code : 'unit';
            }
            if ($unit === null || array_key_exists('code', $data)) {
                $data['code'] = $this->codes->code('unit', $data['code'] ?? null);
                $taken = OrganizationUnit::withTrashed()->where('organization_id', $organizationId)->where('code', $data['code'])
                    ->when($unit, fn ($q) => $q->whereKeyNot($unit->id))->exists();
                if ($taken) {
                    throw ValidationException::withMessages(['code' => 'This code is already used in the organization.']);
                }
            }
            if ($unit !== null && in_array($data['status'] ?? $unit->status, ['inactive', 'archived'], true)
                && Position::query()->where('organization_unit_id', $unit->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['status' => 'The unit still has active positions; move or retire them first.']);
            }

            $old = $unit?->only(array_keys($data));
            $unit ??= new OrganizationUnit(['unit_type' => 'unit', 'status' => 'active']);
            $unit->fill($data + ['organization_id' => $organizationId])->save();
            $this->audit->write($old === null ? 'unit.created' : 'unit.updated', $actor, $unit, $organizationId, $old, $unit->only(array_keys($data)));

            return $unit->fresh(['organization']);
        });
    }

    /** @return list<string> the unit and every unit below it */
    private function descendants(OrganizationUnit $unit): array
    {
        $ids = [(string) $unit->id];
        $children = OrganizationUnit::query()->where('organization_id', $unit->organization_id)->get(['id', 'parent_unit_id'])->groupBy('parent_unit_id');
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($children->get($ids[$i], collect()) as $child) {
                $ids[] = (string) $child->id;
            }
        }

        return $ids;
    }
}
