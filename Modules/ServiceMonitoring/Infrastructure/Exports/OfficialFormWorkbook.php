<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Infrastructure\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The official form as an XLSX workbook (D25): one sheet per form section
 * (I.1, I.2, II, III, IV) and one for the printed notes. A city export holds
 * the Form A and Form B tables one after the other on each sheet.
 */
final class OfficialFormWorkbook implements WithMultipleSheets
{
    /**
     * @param  array<string, mixed>  $document  OfficialFormDocument::build()
     */
    public function __construct(private readonly array $document) {}

    /** @return list<OfficialFormSheet> */
    public function sheets(): array
    {
        $sheets = [];
        foreach ($this->document['sheets'] as $sheet) {
            $sheets[] = new OfficialFormSheet($sheet['title'], $this->document['preamble'], $sheet['tables']);
        }
        $sheets[] = new OfficialFormSheet($this->document['notes_title'], $this->document['preamble'], [[
            'title' => $this->document['notes_title'], 'subtitle' => null, 'header' => [], 'total_rows' => [],
            'body' => array_map(fn (string $n, int $i): array => [$i + 1, $n], $this->document['general_notes'], array_keys($this->document['general_notes'])),
            'notes' => [],
        ]]);

        return $sheets;
    }
}
