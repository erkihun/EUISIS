<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign\Import;

use Generator;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Streams the rows of a staged file as associative arrays keyed by the
 * sheet definition's column names, in chunks, so a large legacy workbook
 * never sits whole in memory. CSV is read line by line; XLSX through a
 * PhpSpreadsheet read filter one chunk of rows at a time. Macros are never
 * executed: cells are read as values and every string is trimmed and
 * treated as untrusted text.
 */
final class DesignImportReader
{
    public const CHUNK = 500;

    public const MAX_ROWS = 50_000;

    /**
     * @param  list<string>  $columns  the definition's column keys
     * @return Generator<int, array{row: int, data: array<string, string|null>}>
     */
    public function rows(string $disk, string $path, string $extension, array $columns): Generator
    {
        $absolute = Storage::disk($disk)->path($path);

        yield from strtolower($extension) === 'csv' ? $this->csvRows($absolute, $columns) : $this->xlsxRows($absolute, $columns);
    }

    /** @return list<string> the header names as found, lower-cased and trimmed */
    public function headers(string $disk, string $path, string $extension): array
    {
        $absolute = Storage::disk($disk)->path($path);
        if (strtolower($extension) === 'csv') {
            $handle = fopen($absolute, 'rb');
            if ($handle === false) {
                return [];
            }
            $first = fgets($handle);
            fclose($handle);
            if ($first === false) {
                return [];
            }
            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;

            return $this->normaliseHeader(str_getcsv(rtrim($first, "\r\n"), ',', '"', '\\'));
        }

        $reader = IOFactory::createReaderForFile($absolute);
        $reader->setReadDataOnly(true);
        $filter = new ChunkReadFilter;
        $filter->setRows(1, 1);
        $reader->setReadFilter($filter);
        $spreadsheet = $reader->load($absolute);
        $sheet = $spreadsheet->getSheet(0);
        $header = $this->normaliseHeader($sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1', null, false, false)[0] ?? []);
        $spreadsheet->disconnectWorksheets();

        return $header;
    }

    /** @return Generator<int, array{row: int, data: array<string, string|null>}> */
    private function csvRows(string $absolute, array $columns): Generator
    {
        $handle = fopen($absolute, 'rb');
        if ($handle === false) {
            return;
        }
        $headerLine = fgets($handle);
        if ($headerLine === false) {
            fclose($handle);

            return;
        }
        $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $headerLine) ?? $headerLine;
        $header = $this->normaliseHeader(str_getcsv(rtrim($headerLine, "\r\n"), ',', '"', '\\'));
        $index = $this->indexOf($header, $columns);
        $number = 1;
        while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $number++;
            if ($number > self::MAX_ROWS + 1) {
                break;
            }
            if ($cells === [null] || count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            yield ['row' => $number, 'data' => $this->pick($cells, $index, $columns)];
        }
        fclose($handle);
    }

    /** @return Generator<int, array{row: int, data: array<string, string|null>}> */
    private function xlsxRows(string $absolute, array $columns): Generator
    {
        $reader = IOFactory::createReaderForFile($absolute);
        $reader->setReadDataOnly(true);
        $filter = new ChunkReadFilter;
        $reader->setReadFilter($filter);

        // Header first.
        $filter->setRows(1, 1);
        $spreadsheet = $reader->load($absolute);
        $sheet = $spreadsheet->getSheet(0);
        $highestColumn = $sheet->getHighestColumn();
        $header = $this->normaliseHeader($sheet->rangeToArray('A1:'.$highestColumn.'1', null, false, false)[0] ?? []);
        $index = $this->indexOf($header, $columns);
        $spreadsheet->disconnectWorksheets();

        for ($start = 2; $start <= self::MAX_ROWS + 1; $start += self::CHUNK) {
            $filter->setRows($start, self::CHUNK);
            $spreadsheet = $reader->load($absolute);
            $sheet = $spreadsheet->getSheet(0);
            $last = min($sheet->getHighestDataRow(), $start + self::CHUNK - 1);
            if ($last < $start) {
                $spreadsheet->disconnectWorksheets();
                break;
            }
            $values = $sheet->rangeToArray('A'.$start.':'.$highestColumn.$last, null, false, false);
            $spreadsheet->disconnectWorksheets();
            $any = false;
            foreach ($values as $offset => $cells) {
                if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                    continue;
                }
                $any = true;
                yield ['row' => $start + $offset, 'data' => $this->pick($cells, $index, $columns)];
            }
            if (! $any && $last < $start + self::CHUNK - 1) {
                break;
            }
        }
    }

    /** @return list<string> */
    private function normaliseHeader(array $cells): array
    {
        return array_map(fn ($c) => strtolower(trim((string) $c)), $cells);
    }

    /** @return array<string, int> column key => cell index */
    private function indexOf(array $header, array $columns): array
    {
        $index = [];
        foreach ($columns as $column) {
            $at = array_search($column, $header, true);
            if ($at !== false) {
                $index[$column] = (int) $at;
            }
        }

        return $index;
    }

    /** @return array<string, string|null> */
    private function pick(array $cells, array $index, array $columns): array
    {
        $data = [];
        foreach ($columns as $column) {
            $raw = isset($index[$column]) ? ($cells[$index[$column]] ?? null) : null;
            if ($raw instanceof \DateTimeInterface) {
                $raw = $raw->format('Y-m-d');
            } elseif ((is_int($raw) || is_float($raw)) && (str_ends_with($column, '_from') || str_ends_with($column, '_to') || str_ends_with($column, '_date'))) {
                // A date cell read as data is Excel's serial day number.
                $raw = ExcelDate::excelToDateTimeObject($raw)->format('Y-m-d');
            }
            $value = trim((string) $raw);
            $data[$column] = $value === '' ? null : $value;
        }

        return $data;
    }
}
