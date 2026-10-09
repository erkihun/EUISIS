<?php

declare(strict_types=1);

namespace App\Exports\Cafeteria;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final readonly class ProviderTransactionsExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithStyles
{
    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function __construct(
        private array $headers,
        private array $rows,
    ) {}

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        return [$this->headers, ...$this->rows];
    }

    /** @return array<int|string, mixed> */
    public function styles(Worksheet $sheet): array
    {
        // Row 1 = header: dark blue background, white text, normal weight (Ethiopic-safe).
        // Bold is kept here only because XLSX rendering is separate from DomPDF and
        // most font renderers (Excel/LibreOffice) handle Ethiopic bold correctly.
        $highestCol = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$highestCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E3A8A']],
            'alignment' => ['wrapText' => true],
        ]);

        // Right-align amount columns O and P (indices 14 and 15, 1-based = O, P)
        $sheet->getStyle('O2:O'.($sheet->getHighestRow()))->getAlignment()->setHorizontal('right');
        $sheet->getStyle('P2:P'.($sheet->getHighestRow()))->getAlignment()->setHorizontal('right');

        // Freeze top row
        $sheet->freezePane('A2');

        return [];
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        // Columns O, P are subsidy/employee_payable — display as number
        return [
            'O' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2,
            'P' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2,
        ];
    }
}
