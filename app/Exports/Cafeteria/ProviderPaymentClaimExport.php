<?php

declare(strict_types=1);

namespace App\Exports\Cafeteria;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final readonly class ProviderPaymentClaimExport implements FromArray, ShouldAutoSize, WithStyles
{
    /**
     * @param  array<int, array<int, mixed>>  $summaryRows
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function __construct(
        private array $summaryRows,
        private array $headers,
        private array $rows,
    ) {}

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        return [
            ...$this->summaryRows,
            [],
            $this->headers,
            ...$this->rows,
        ];
    }

    /** @return array<int|string, mixed> */
    public function styles(Worksheet $sheet): array
    {
        $transactionHeaderRow = count($this->summaryRows) + 2;
        $highestCol = $sheet->getHighestColumn();

        // Row 1 — payment claim title: large, bold
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 13],
        ]);

        // Summary rows (2 … transactionHeaderRow-2): key-value style
        for ($r = 2; $r < $transactionHeaderRow - 1; $r++) {
            $sheet->getStyle("A{$r}")->applyFromArray([
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F1F5F9']],
            ]);
        }

        // Transaction header row — blue background, white text
        $sheet->getStyle("A{$transactionHeaderRow}:{$highestCol}{$transactionHeaderRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E3A8A']],
            'alignment' => ['wrapText' => true],
        ]);

        // Right-align amount columns relative to transaction header
        $amountCols = ['O', 'P'];
        $lastRow = $sheet->getHighestRow();
        foreach ($amountCols as $col) {
            $dataStart = $transactionHeaderRow + 1;
            if ($dataStart <= $lastRow) {
                $sheet->getStyle("{$col}{$dataStart}:{$col}{$lastRow}")->getAlignment()->setHorizontal('right');
            }
        }

        // Freeze at transaction header
        $sheet->freezePane("A{$transactionHeaderRow}");

        return [];
    }
}
