<?php

declare(strict_types=1);

namespace App\Exports\Cafeteria;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TransactionStatementExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithStyles
{
    public function __construct(private array $data) {}

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function array(): array
    {
        $d = $this->data;
        $rows = [
            [__('cafeteria-statement.title')],
            [__('cafeteria-statement.provider'), $d['providerName']],
            [__('cafeteria-statement.period'), $d['periodLabel']],
            [__('cafeteria-statement.prepared'), $d['actor'], $d['generatedAt']],
            [__('cafeteria-statement.filters'), $d['statusLabel'], $d['filters']['extra_only'] === '1' ? __('cafeteria-statement.extra_only') : ''],
            [__('cafeteria-statement.note')],
            [__('cafeteria-statement.claim'), (float) $d['summary']['subsidy']],
            [],
            array_map(fn ($key) => __('cafeteria-statement.'.$key), ['number', 'date', 'employee', 'provider', 'status', 'meal', 'subsidy', 'employee_payable', 'deduction']),
        ];
        foreach ($d['rows'] as $row) {
            $rows[] = [$row['number'], $row['date'],
                $row['employee_number'].' / '.$row['employee_name'],
                $row['provider'], $row['status'],
                $row['meal_amount'], $row['subsidy_amount_applied'],
                $row['employee_payable_amount'], $row['deduction_amount']];
        }
        $s = $d['summary'];
        $rows[] = [__('cafeteria-statement.accepted_totals'), null, null, null, $s['accepted'], (float) $s['meals'], (float) $s['subsidy'], (float) $s['employee_payable'], (float) $s['deductions']];

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $last = $sheet->getHighestRow();
        $sheet->getStyle('F10:I'.$last)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('B7')->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->freezePane('A10');
        $sheet->setAutoFilter('A9:I'.max(9, $last - 1));
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(9, 9);

        return [1 => ['font' => ['bold' => true, 'size' => 16]], 9 => ['font' => ['bold' => true]], $last => ['font' => ['bold' => true]]];
    }
}
