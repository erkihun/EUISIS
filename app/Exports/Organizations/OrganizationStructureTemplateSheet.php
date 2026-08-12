<?php

declare(strict_types=1);

namespace App\Exports\Organizations;

use App\Services\OrganizationStructureImport\StructureSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One sheet of the blank structure template: a single header row listing the
 * sheet's columns. Required columns are marked with a trailing asterisk in the
 * *styling* only — the header text itself stays exactly as the reader expects.
 */
final readonly class OrganizationStructureTemplateSheet implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    public function __construct(private StructureSheet $sheet) {}

    /** @return array<int, array<int, string>> */
    public function array(): array
    {
        return [$this->sheet->knownColumns()];
    }

    public function title(): string
    {
        return $this->sheet->value;
    }

    /** @return array<int|string, mixed> */
    public function styles(Worksheet $worksheet): array
    {
        $highestColumn = $worksheet->getHighestColumn();

        $worksheet->getStyle("A1:{$highestColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E3A8A']],
        ]);

        $worksheet->freezePane('A2');

        // Red  = required business field, may not be left blank.
        // Green = the code column: leave it blank and the Code Rules fill it in.
        // Blue  = optional.
        $required = $this->sheet->requiredColumns();
        $codeColumn = $this->sheet->codeColumn();

        foreach ($this->sheet->knownColumns() as $index => $column) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);

            if (in_array($column, $required, true)) {
                $worksheet->getStyle("{$letter}1")->applyFromArray([
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'B91C1C']],
                ]);

                continue;
            }

            if ($column === $codeColumn) {
                $worksheet->getStyle("{$letter}1")->applyFromArray([
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '047857']],
                ]);

                $worksheet->getComment("{$letter}1")->getText()->createTextRun(
                    __('organization-structure-import.auto_generate_hint'),
                );
            }
        }

        return [];
    }
}
