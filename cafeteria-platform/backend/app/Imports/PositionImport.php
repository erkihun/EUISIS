<?php

declare(strict_types=1);

namespace App\Imports;

use App\Actions\SaveEstablishment;
use App\Actions\SavePosition;
use App\Models\ImportBatch;
use App\Models\JobGrade;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;

/**
 * Positions (ADR 0009) through SavePosition (codes, units, scope) and, when the
 * file gives seats, an approved establishment through SaveEstablishment.
 */
final class PositionImport extends ImportDefinition
{
    public function columns(): array
    {
        return ['code', 'title_en', 'title_am', 'organization_code', 'unit_code', 'grade_level', 'approved_slots', 'effective_from'];
    }

    public function permission(): string
    {
        return 'positions.create';
    }

    public function validate(array $row, int $number, User $uploader): array
    {
        $errors = $this->basic($row, [
            'code' => ['nullable', 'max:255'], 'title_en' => ['required', 'max:255'], 'title_am' => ['nullable', 'max:255'],
            'organization_code' => ['required'], 'unit_code' => ['nullable', 'max:255'], 'grade_level' => ['nullable', 'max:255'],
            'approved_slots' => ['nullable', 'integer', 'min:1', 'max:1000'], 'effective_from' => ['nullable', 'date'],
        ]);
        if ($row['code'] !== null) {
            if (Position::withTrashed()->where('job_position_code', $row['code'])->exists()) {
                $errors[] = $this->error('code', 'DUPLICATE_CODE', 'A position with this code already exists.');
            } elseif (($earlier = $this->remember('code', $row['code'], $number)) !== null) {
                $errors[] = $this->error('code', 'DUPLICATE_IN_FILE', "This code is already on row {$earlier}.");
            }
        }
        $organization = $row['organization_code'] === null ? null : Organization::query()->where('code', $row['organization_code'])->first(['id', 'status']);
        if ($row['organization_code'] !== null && $organization === null) {
            $errors[] = $this->error('organization_code', 'UNKNOWN_ORGANIZATION', 'No organization has this code.');
        } elseif ($organization !== null && ! $this->inScope($uploader, (string) $organization->id)) {
            $errors[] = $this->error('organization_code', 'OUT_OF_SCOPE_ORGANIZATION', 'This organization is outside your organizations.');
        }
        if ($organization !== null && $row['unit_code'] !== null
            && ! OrganizationUnit::query()->where('organization_id', $organization->id)->where('code', $row['unit_code'])->exists()) {
            $errors[] = $this->error('unit_code', 'UNKNOWN_UNIT', 'The organization has no unit with this code.');
        }
        if ($row['grade_level'] !== null && ! JobGrade::query()->where('code', $row['grade_level'])->where('is_active', true)->exists()) {
            $errors[] = $this->error('grade_level', 'UNKNOWN_GRADE', 'No active job grade has this code.');
        }
        if ($row['effective_from'] !== null && $this->pastDate($row['effective_from']) === null) {
            $errors[] = $this->error('effective_from', 'INVALID_DATE', 'Use a date that is not in the future.');
        }

        return $errors;
    }

    public function process(array $row, ImportBatch $batch, User $actor): array
    {
        $organizationId = (string) Organization::query()->where('code', $row['organization_code'])->value('id');
        $unitId = $row['unit_code'] === null ? null
            : OrganizationUnit::query()->where('organization_id', $organizationId)->where('code', $row['unit_code'])->value('id');
        $position = app(SavePosition::class)->execute(null, array_filter([
            'organization_id' => $organizationId,
            'organization_unit_id' => $unitId,
            'job_position_code' => $row['code'],
            'title_en' => $row['title_en'],
            'title_am' => $row['title_am'],
            'grade_level' => $row['grade_level'],
        ], fn ($value): bool => $value !== null), $actor);
        if ($row['approved_slots'] !== null) {
            app(SaveEstablishment::class)->approve($position, [
                'approved_slots' => (int) $row['approved_slots'],
                'effective_from' => $row['effective_from'] ?? now()->toDateString(),
                'approval_reference' => 'Import '.$batch->id,
            ], $actor);
        }

        return ['Position', (string) $position->id];
    }
}
