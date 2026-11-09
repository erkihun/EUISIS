<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Infrastructure\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * One worksheet of the official form: a preamble (letterhead, title, range,
 * notices), then each table with its grouped headings (merged cells), body,
 * totals row (bold) and printed notes. Cells are written as plain values:
 * nothing a user typed becomes a formula.
 */
final class OfficialFormSheet extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithEvents, WithTitle
{
    /** @var list<list<string|int>> */
    private array $grid = [];

    /** @var list<array{0: int, 1: int, 2: int, 3: int}> */
    private array $merges = [];

    /** @var list<int> */
    private array $bold = [];

    /** @var list<array{0: int, 1: int, 2: int}> header/body blocks: first row, last row, columns */
    private array $boxes = [];

    /**
     * @param  list<string>  $preamble
     * @param  list<array<string, mixed>>  $tables
     */
    public function __construct(private readonly string $sheetTitle, array $preamble, array $tables)
    {
        foreach ($preamble as $line) {
            $this->line([$line], true);
        }
        $this->grid[] = [''];
        foreach ($tables as $table) {
            $this->table($table);
        }
    }

    /** @return list<list<string|int>> */
    public function array(): array
    {
        return $this->grid;
    }

    /** Text is always written as text, so a value starting with "=" is never a formula. */
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function title(): string
    {
        return mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $this->sheetTitle) ?? 'Sheet', 0, 31);
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event): void {
            $sheet = $event->sheet->getDelegate();
            foreach ($this->merges as [$r1, $c1, $r2, $c2]) {
                $sheet->mergeCells(Coordinate::stringFromColumnIndex($c1).$r1.':'.Coordinate::stringFromColumnIndex($c2).$r2);
            }
            foreach ($this->bold as $row) {
                $sheet->getStyle('A'.$row.':'.Coordinate::stringFromColumnIndex(max(1, count($this->grid[$row - 1] ?? [1]))).$row)->getFont()->setBold(true);
            }
            foreach ($this->boxes as [$first, $last, $columns]) {
                $range = 'A'.$first.':'.Coordinate::stringFromColumnIndex($columns).$last;
                $style = $sheet->getStyle($range);
                $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $style->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            }
            for ($c = 1; $c <= 16; $c++) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth($c === 1 ? 6 : 20);
            }
        }];
    }

    /** @param array<string, mixed> $table */
    private function table(array $table): void
    {
        $this->line([(string) $table['title']], true);
        if (! empty($table['subtitle'])) {
            $this->line([(string) $table['subtitle']], true);
        }
        $first = count($this->grid) + 1;
        $columns = 0;
        // Place header cells on an occupancy grid so rowspans and colspans land where the form has them.
        $occupied = [];
        $headerRows = [];
        foreach ($table['header'] as $r => $cells) {
            $row = [];
            $col = 1;
            foreach ($cells as $cell) {
                while (isset($occupied[$r][$col])) {
                    $col++;
                }
                $row[$col] = $cell['text'];
                for ($dr = 0; $dr < $cell['rowspan']; $dr++) {
                    for ($dc = 0; $dc < $cell['colspan']; $dc++) {
                        $occupied[$r + $dr][$col + $dc] = true;
                    }
                }
                if ($cell['colspan'] > 1 || $cell['rowspan'] > 1) {
                    $this->merges[] = [$first + $r, $col, $first + $r + $cell['rowspan'] - 1, $col + $cell['colspan'] - 1];
                }
                $col += $cell['colspan'];
                $columns = max($columns, $col - 1);
            }
            $headerRows[] = $row;
        }
        foreach ($headerRows as $row) {
            $line = array_fill(0, $columns, '');
            foreach ($row as $col => $text) {
                $line[$col - 1] = $text;
            }
            $this->line($line, true);
        }
        foreach ($table['body'] as $index => $values) {
            $this->line(array_values($values), in_array($index, $table['total_rows'], true));
            $columns = max($columns, count($values));
        }
        $this->boxes[] = [$first, count($this->grid), $columns];
        foreach ($table['notes'] as $note) {
            $this->line([(string) $note], false);
        }
        $this->grid[] = [''];
    }

    /** @param list<string|int> $values */
    private function line(array $values, bool $bold): void
    {
        $this->grid[] = $values;
        if ($bold) {
            $this->bold[] = count($this->grid);
        }
    }
}
