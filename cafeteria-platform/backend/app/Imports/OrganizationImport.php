<?php

declare(strict_types=1);

namespace App\Imports;

use App\Actions\ManageHierarchyVersion;
use App\Actions\SaveOrganization;
use App\Models\HierarchyVersion;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Organizations (ADR 0009). Each row creates an organization (a draft unless
 * the file says active) through SaveOrganization, without touching the
 * published hierarchy: the parents named in the file go into one hierarchy
 * draft for the batch, which is reviewed, approved and published through the
 * governed workflow like any other. Hence city-wide hierarchy permission.
 */
final class OrganizationImport extends ImportDefinition
{
    public function columns(): array
    {
        return ['code', 'name_en', 'name_am', 'type_code', 'status', 'parent_code', 'effective_from'];
    }

    public function permission(): string
    {
        return 'hierarchy-versions.manage';
    }

    public function validate(array $row, int $number, User $uploader): array
    {
        $errors = $this->basic($row, [
            'code' => ['required', 'max:255'], 'name_en' => ['required', 'max:255'], 'name_am' => ['nullable', 'max:255'],
            'type_code' => ['required'], 'status' => ['nullable', 'in:draft,active'], 'parent_code' => ['nullable', 'max:255'],
            'effective_from' => ['nullable', 'date'],
        ]);
        if ($row['code'] !== null) {
            if (Organization::withTrashed()->where('code', $row['code'])->exists()) {
                $errors[] = $this->error('code', 'DUPLICATE_CODE', 'An organization with this code already exists.');
            } elseif (($earlier = $this->remember('code', $row['code'], $number)) !== null) {
                $errors[] = $this->error('code', 'DUPLICATE_IN_FILE', "This code is already on row {$earlier}.");
            }
        }
        if ($row['type_code'] !== null && ! OrganizationType::query()->where('code', $row['type_code'])->exists()) {
            $errors[] = $this->error('type_code', 'UNKNOWN_ORGANIZATION_TYPE', 'No organization type has this code.');
        }
        if ($row['parent_code'] !== null) {
            if ($row['parent_code'] === $row['code']) {
                $errors[] = $this->error('parent_code', 'INVALID_PARENT', 'An organization cannot be its own parent.');
            } elseif (! Organization::query()->where('code', $row['parent_code'])->exists() && ! $this->inFile($row['parent_code'])) {
                // A parent further down the file is checked again before processing (ImportValidator::recheckParents).
                $this->seen['pending_parent'][$number] = $row['parent_code'];
            }
        }
        if ($row['effective_from'] !== null && $this->pastDate($row['effective_from']) === null) {
            $errors[] = $this->error('effective_from', 'INVALID_DATE', 'Use a date that is not in the future.');
        }
        if (! $uploader->isCityWide()) {
            $errors[] = $this->error('code', 'OUT_OF_SCOPE_ORGANIZATION', 'Organizations are imported by a city-wide account.');
        }

        return $errors;
    }

    /**
     * Parents named before their own row: known once the whole file is read.
     *
     * @return array<int, array{field: string, code: string, message: string}> row number => error
     */
    public function unresolvedParents(): array
    {
        $errors = [];
        foreach ($this->seen['pending_parent'] ?? [] as $number => $parent) {
            if (! $this->inFile($parent)) {
                $errors[$number] = $this->error('parent_code', 'UNKNOWN_PARENT', 'No organization, in the Core or in this file, has this code.');
            }
        }

        return $errors;
    }

    public function process(array $row, ImportBatch $batch, User $actor): array
    {
        $type = OrganizationType::query()->where('code', $row['type_code'])->value('id');
        $organization = app(SaveOrganization::class)->execute(null, [
            'organization_type_id' => $type,
            'code' => $row['code'],
            'name_en' => $row['name_en'],
            'name_am' => $row['name_am'],
            'status' => $row['status'] ?? 'draft',
            'effective_from' => $row['effective_from'] ?? now()->toDateString(),
        ], $actor);

        return ['Organization', (string) $organization->id];
    }

    /** The parents go into one hierarchy draft for the batch (never the published hierarchy). */
    public function finish(ImportBatch $batch, User $actor): void
    {
        $placed = ImportRow::query()->where('import_batch_id', $batch->id)->where('status', ImportRow::PROCESSED)->get()
            ->filter(fn (ImportRow $row): bool => ($row->payload['parent_code'] ?? null) !== null);
        if ($placed->isEmpty()) {
            return;
        }
        $hierarchy = app(ManageHierarchyVersion::class);
        $version = $batch->hierarchy_version_id === null ? null : HierarchyVersion::query()->find($batch->hierarchy_version_id);
        if ($version === null) {
            $version = $hierarchy->create([
                'version_name' => 'Import '.substr((string) $batch->id, -12),
                'effective_from' => now()->toDateString(),
                'notes' => 'Prepared from import '.$batch->id.' ('.$batch->source_filename.'); review before approval.',
            ], $actor);
            $batch->forceFill(['hierarchy_version_id' => $version->id])->save();
        }
        $problems = [];
        foreach ($placed as $row) {
            $parentId = Organization::query()->where('code', $row->payload['parent_code'])->value('id');
            try {
                $hierarchy->changeParent($version, (string) $row->result_id, $parentId === null ? null : (string) $parentId, $actor);
            } catch (ValidationException $exception) {
                $problems[] = ['row' => $row->row_number, 'message' => collect($exception->errors())->flatten()->first()];
            }
        }
        if ($problems !== []) {
            $batch->forceFill(['error_summary' => array_merge($batch->error_summary ?? [], ['hierarchy' => $problems])])->save();
        }
    }

    private function inFile(string $code): bool
    {
        return isset($this->seen['code'][mb_strtolower($code)]);
    }
}
