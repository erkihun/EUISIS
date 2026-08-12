<?php

declare(strict_types=1);

namespace App\Services\OrganizationStructureImport;

use Illuminate\Support\Collection;

/**
 * A parsed workbook: for each sheet that was present, the header columns that
 * were found and the data rows, each row tagged with its Excel row number.
 *
 * Structural problems detected while reading (missing sheet, missing column)
 * are collected here rather than thrown, so the preview can report all of them
 * at once instead of failing on the first.
 */
final class StructureWorkbook
{
    /**
     * @param  array<string, list<string>>  $columns  sheet value => found column names
     * @param  array<string, list<array{__row: int, ...}>>  $rows  sheet value => data rows
     * @param  list<ImportIssue>  $issues  structural (sheet/column-level) problems
     */
    public function __construct(
        private readonly array $columns = [],
        private readonly array $rows = [],
        private readonly array $issues = [],
    ) {}

    /** @return list<ImportIssue> */
    public function structuralIssues(): array
    {
        return $this->issues;
    }

    public function hasSheet(StructureSheet $sheet): bool
    {
        return array_key_exists($sheet->value, $this->rows);
    }

    /** @return list<string> */
    public function columns(StructureSheet $sheet): array
    {
        return $this->columns[$sheet->value] ?? [];
    }

    public function hasColumn(StructureSheet $sheet, string $column): bool
    {
        return in_array($column, $this->columns($sheet), true);
    }

    /**
     * Data rows for a sheet. Each row is an associative array of the sheet's
     * known columns, plus `__row` carrying the 1-based Excel row number.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(StructureSheet $sheet): Collection
    {
        return collect($this->rows[$sheet->value] ?? []);
    }

    /** The single organization row, or null when the sheet is missing/empty. */
    public function organizationRow(): ?array
    {
        return $this->rows(StructureSheet::Organization)->first();
    }
}
