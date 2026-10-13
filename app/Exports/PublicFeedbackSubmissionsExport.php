<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * The public submissions report as one flat sheet. Strings are bound as
 * text so a message beginning with "=" never executes in a workbook, and
 * CSV rows go through csv_safe_row() because a CSV carries no cell types
 * (brief §87).
 */
class PublicFeedbackSubmissionsExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings
{
    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $labels  column => translated heading
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        private readonly array $columns,
        private readonly array $labels,
        private readonly array $rows,
        private readonly bool $csv,
    ) {}

    public function headings(): array
    {
        return array_map(fn (string $column): string => $this->labels[$column] ?? $column, $this->columns);
    }

    public function array(): array
    {
        return array_map(function (array $row): array {
            $cells = array_map(fn (string $column) => $row[$column] ?? null, $this->columns);

            return $this->csv ? csv_safe_row($cells) : $cells;
        }, $this->rows);
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
