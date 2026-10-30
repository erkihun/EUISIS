<?php

declare(strict_types=1);

namespace Modules\Transport\Infrastructure\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** One sheet: a styled, frozen header row and the localized rows beneath it. */
final readonly class TransportTabularExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    /**
     * @param  list<string>  $headers
     * @param  list<array<int, mixed>>  $rows
     */
    public function __construct(private string $title, private array $headers, private array $rows) {}

    /** @return list<array<int, mixed>> */
    public function array(): array
    {
        return [$this->headers, ...$this->rows];
    }

    public function title(): string
    {
        return mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $this->title) ?? 'Transport', 0, 31);
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1E3A8A']]]];
    }
}
