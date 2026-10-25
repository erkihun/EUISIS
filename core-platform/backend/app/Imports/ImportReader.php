<?php

declare(strict_types=1);

namespace App\Imports;

use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads an import file one row at a time (OpenSpout streams; the file is never
 * loaded whole). The first row of the first sheet is the header; blank rows are
 * skipped; each row comes keyed by its lower-cased header.
 */
final class ImportReader
{
    /** @return list<string> the header of the file */
    public static function header(string $path, string $extension): array
    {
        foreach (self::raw($path, $extension) as $values) {
            return self::cleanHeader($values);
        }

        return [];
    }

    /** @return \Generator<int, array<string, mixed>> spreadsheet row number => cells by header */
    public static function rows(string $path, string $extension): \Generator
    {
        $header = null;
        $number = 0;
        foreach (self::raw($path, $extension) as $values) {
            $number++;
            if ($header === null) {
                $header = self::cleanHeader($values);

                continue;
            }
            if (array_filter($values, fn ($value): bool => $value !== null && trim((string) ($value instanceof \DateTimeInterface ? 'date' : $value)) !== '') === []) {
                continue;
            }
            $cells = [];
            foreach ($header as $index => $column) {
                if ($column !== '') {
                    $cells[$column] = $values[$index] ?? null;
                }
            }
            yield $number => $cells;
        }
    }

    /** @return \Generator<int, list<mixed>> */
    private static function raw(string $path, string $extension): \Generator
    {
        $reader = $extension === 'xlsx' ? new XlsxReader : new CsvReader;
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield $row->toArray();
                }
                break; // the first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private static function cleanHeader(array $values): array
    {
        return array_map(fn ($value): string => mb_strtolower(trim(str_replace("\u{FEFF}", '', (string) $value))), $values);
    }
}
