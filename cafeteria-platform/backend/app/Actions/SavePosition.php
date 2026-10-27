<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\JobGrade;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CodeGenerator;
use App\Services\OrganizationScope;
use App\Services\PositionHistoryLog;
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
    /** The fields whose changes the position's history keeps (ADR 0010). */
    private const HISTORY = ['organization_id', 'organization_unit_id', 'title_en', 'title_am', 'job_grade_id', 'job_level_id', 'grade_level', 'occupation_id', 'profession_id',
        'position_category_id', 'employment_type_id', 'lifecycle_status', 'effective_from', 'effective_to', 'job_position_code'];

    public function __construct(private AuditWriter $audit, private OrganizationScope $scope, private CodeGenerator $codes, private PositionHistoryLog $history) {}

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

            $data = $this->masters($data, $position);

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
            $snapshot = $position?->only(self::HISTORY);
            // A new position is approved when its creator may approve positions; otherwise it waits as a draft (ADR 0010).
            $approved = $actor->can('positions.approve');
            $position ??= new Position([
                'lifecycle_status' => $approved ? Position::APPROVED : Position::DRAFT, 'effective_from' => now()->toDateString(),
                'created_by' => $actor->getKey(), 'approved_by' => $approved ? $actor->getKey() : null, 'approved_at' => $approved ? now() : null,
            ]);
            $position->fill(array_diff_key($data, ['is_active' => true]) + (array_key_exists('is_active', $data) && $snapshot !== null ? ['is_active' => $data['is_active']] : []))->save();
            if ($snapshot === null) {
                $this->history->record($position, 'created', ['lifecycle_status' => ['from' => null, 'to' => $position->lifecycle_status]], $actor);
            } else {
                $this->history->changes($position, $snapshot, $position->only(self::HISTORY), $actor);
            }

            // Holders follow their seat to its new unit (ADR 0005, decision 1).
            if ($old !== null && (string) ($old['organization_unit_id'] ?? '') !== (string) $position->organization_unit_id) {
                $position->holders()->update(['organization_unit_id' => $position->organization_unit_id]);
            }
            $this->audit->write($old === null ? 'position.created' : 'position.updated', $actor, $position, (string) $organizationId, $old, $position->only(array_keys($data)));

            return $position->fresh(['organization', 'unit']);
        });
    }

    /**
     * The job master references (ADR 0010): a newly chosen value must be active;
     * the EUISIS form's grade (its code) is the grade master; an empty choice clears it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function masters(array $data, ?Position $position): array
    {
        if (array_key_exists('grade_level', $data) && ! array_key_exists('job_grade_id', $data)) {
            $code = trim((string) $data['grade_level']);
            $grade = $code === '' ? null : JobGrade::query()->where('code', $code)->first();
            if ($code !== '' && $grade === null) {
                throw ValidationException::withMessages(['grade_level' => 'Choose a grade from the list.']);
            }
            $data['job_grade_id'] = $grade?->id;
        } elseif (array_key_exists('job_grade_id', $data)) {
            // The free-text grade the EUISIS screens show follows the master.
            $data['grade_level'] = $data['job_grade_id'] === null || $data['job_grade_id'] === '' ? null : JobGrade::query()->whereKey($data['job_grade_id'])->value('code');
        }
        foreach (ManageJobMasters::KINDS as [$class, $column]) {
            if (! array_key_exists($column, $data)) {
                continue;
            }
            $value = $data[$column] === '' ? null : $data[$column];
            $data[$column] = $value;
            if ($value !== null && (string) $position?->{$column} !== (string) $value && ! $class::query()->whereKey($value)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([$column => 'Choose an active value from the list.']);
            }
        }

        return $data;
    }
}
