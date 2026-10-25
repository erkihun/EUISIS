<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CodeGenerator;
use App\Services\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes a position (EUISIS CreatePositionAction / UpdatePositionAction):
 * its organization comes from the unit when only a unit is given, the unit must
 * belong to that organization, and a blank job position code is generated.
 * A held position cannot be retired, and an occupied position does not move to
 * another organization directly (ADR 0005: restructuring is the governed path).
 */
final readonly class SavePosition
{
    public function __construct(private AuditWriter $audit, private OrganizationScope $scope, private CodeGenerator $codes) {}

    /** @param array<string, mixed> $data organization_id, organization_unit_id, job_position_code, title_en, title_am, grade_level, is_active */
    public function execute(?Position $position, array $data, User $actor): Position
    {
        return DB::transaction(function () use ($position, $data, $actor): Position {
            if ($position !== null) {
                $position = Position::query()->lockForUpdate()->findOrFail($position->id);
            }
            $unitId = $data['organization_unit_id'] ?? $position?->organization_unit_id;
            $organizationId = $data['organization_id'] ?? null;
            if (($organizationId === null || $organizationId === '') && $unitId !== null) {
                $organizationId = OrganizationUnit::query()->whereKey($unitId)->value('organization_id');
            }
            $organizationId ??= $position?->organization_id;
            if ($organizationId === null) {
                throw ValidationException::withMessages(['organization_id' => 'Choose the organization or the unit of the position.']);
            }
            $data['organization_id'] = $organizationId;

            if (! $this->scope->allows($actor, (string) $organizationId) || ($position !== null && ! $this->scope->allows($actor, (string) $position->organization_id))) {
                throw ValidationException::withMessages(['organization_id' => 'The organization is outside your organizations.']);
            }
            if ($unitId !== null && (string) OrganizationUnit::query()->whereKey($unitId)->value('organization_id') !== (string) $organizationId) {
                throw ValidationException::withMessages(['organization_unit_id' => 'The unit must belong to the position\'s organization.']);
            }

            $held = $position !== null && $position->holders()->exists();
            if ($held && (string) $organizationId !== (string) $position->organization_id) {
                throw ValidationException::withMessages(['organization_id' => 'An occupied position cannot move to another organization; transfer its holders first.']);
            }
            if ($held && array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => 'A position someone holds cannot be retired.']);
            }

            if ($position === null || array_key_exists('job_position_code', $data)) {
                $data['job_position_code'] = $this->codes->code('position', $data['job_position_code'] ?? null);
                $taken = Position::withTrashed()->where('job_position_code', $data['job_position_code'])->when($position, fn ($q) => $q->whereKeyNot($position->id))->exists();
                if ($taken) {
                    throw ValidationException::withMessages(['job_position_code' => 'This code is already used.']);
                }
            }

            $old = $position?->only(array_keys($data));
            $position ??= new Position(['is_active' => true, 'effective_from' => now()->toDateString()]);
            $position->fill($data)->save();

            // Holders follow their seat to its new unit (ADR 0005, decision 1).
            if ($old !== null && (string) ($old['organization_unit_id'] ?? '') !== (string) $position->organization_unit_id) {
                $position->holders()->update(['organization_unit_id' => $position->organization_unit_id]);
            }
            $this->audit->write($old === null ? 'position.created' : 'position.updated', $actor, $position, (string) $organizationId, $old, $position->only(array_keys($data)));

            return $position->fresh(['organization', 'unit']);
        });
    }
}
