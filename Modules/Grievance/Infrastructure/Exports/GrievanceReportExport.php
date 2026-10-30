<?php

declare(strict_types=1);

namespace App\Exports\Grievances;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * One grievance report as a flat sheet (xlsx/csv) and the same rows for the
 * PDF view. Strings are bound explicitly as text so a value beginning with
 * "=" can never execute as a formula.
 */
class GrievanceReportExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings
{
    /**
     * @param  array{columns: list<string>, rows: list<array<string, mixed>>}  $report
     * @param  array<string, string>  $labels  column => translated heading
     */
    public function __construct(private readonly array $report, private readonly array $labels) {}

    public function headings(): array
    {
        return array_map(fn (string $c) => $this->labels[$c] ?? $c, $this->report['columns']);
    }

    public function array(): array
    {
        return array_map(fn (array $row) => array_map(function (string $column) use ($row) {
            $value = $row[$column] ?? null;

            return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
        }, $this->report['columns']), $this->report['rows']);
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
