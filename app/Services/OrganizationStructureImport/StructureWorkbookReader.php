<?php

declare(strict_types=1);

namespace App\Services\OrganizationStructureImport;

use App\Exceptions\InvalidStructureWorkbookException;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Turns an uploaded .xlsx/.xls/.csv into a {@see StructureWorkbook}.
 *
 * Reads through PhpSpreadsheet (already a dependency of maatwebsite/excel)
 * rather than a per-sheet Maatwebsite Import class, because the wizard needs
 * the *sheet titles and header names themselves* in order to report "required
 * sheet missing" / "required column missing" — information a
 * ToCollection/ToModel import would have already discarded.
 *
 * Nothing here rejects bad *data*; the reader only reports problems that make
 * the file unreadable as a structure workbook (unparseable file, absent sheet,
 * absent column). Row-level rules live in
 * {@see OrganizationStructureImportValidator}.
 */
class StructureWorkbookReader
{
    /** Guard against a runaway sheet: rows past this limit are not read. */
    private const MAX_ROWS_PER_SHEET = 10_000;

    /**
     * @throws InvalidStructureWorkbookException when the file cannot be opened as a spreadsheet
     */
    public function read(UploadedFile $file): StructureWorkbook
    {
        $spreadsheet = $this->load($file);

        $columns = [];
        $rows = [];
        $issues = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $sheet = StructureSheet::fromTitle($worksheet->getTitle());

            if ($sheet === null) {
                continue; // Unknown sheets are ignored, not an error.
            }

            [$sheetColumns, $sheetRows] = $this->readSheet($worksheet, $sheet);

            $columns[$sheet->value] = $sheetColumns;
            $rows[$sheet->value] = $sheetRows;
        }

        $spreadsheet->disconnectWorksheets();

        foreach (StructureSheet::cases() as $sheet) {
            if (! array_key_exists($sheet->value, $rows)) {
                if ($sheet->isRequired()) {
                    $issues[] = ImportIssue::error(
                        $sheet,
                        null,
                        __('organization-structure-import.errors.required_sheet_missing', ['sheet' => $sheet->value]),
                    );
                }

                continue;
            }

            foreach ($sheet->requiredColumns() as $column) {
                if (! in_array($column, $columns[$sheet->value], true)) {
                    $issues[] = ImportIssue::error(
                        $sheet,
                        null,
                        __('organization-structure-import.errors.required_column_missing', [
                            'sheet' => $sheet->value,
                            'column' => $column,
                        ]),
                        $column,
                    );
                }
            }
        }

        return new StructureWorkbook($columns, $rows, $issues);
    }

    /**
     * @return array{0: list<string>, 1: list<array<string, mixed>>}
     */
    private function readSheet(Worksheet $worksheet, StructureSheet $sheet): array
    {
        $known = $sheet->knownColumns();

        // Map each header cell to a known column name, keyed by column index so
        // the columns may appear in any order and extra columns are skipped.
        $headerByIndex = [];
        $foundColumns = [];

        $headerRow = $worksheet->getRowIterator(1, 1)->current();
        $headerCells = $headerRow?->getCellIterator();
        $headerCells?->setIterateOnlyExistingCells(true);

        foreach ($headerCells ?? [] as $index => $cell) {
            $header = StructureSheet::normalize((string) $cell->getValue());

            if ($header === '') {
                continue;
            }

            foreach ($known as $column) {
                if (StructureSheet::normalize($column) === $header && ! in_array($column, $foundColumns, true)) {
                    $headerByIndex[$index] = $column;
                    $foundColumns[] = $column;
                    break;
                }
            }
        }

        if ($foundColumns === []) {
            return [[], []];
        }

        $rows = [];
        $lastRow = min($worksheet->getHighestDataRow(), self::MAX_ROWS_PER_SHEET + 1);

        for ($rowNumber = 2; $rowNumber <= $lastRow; $rowNumber++) {
            $values = [];

            foreach ($headerByIndex as $columnIndex => $column) {
                $values[$column] = $this->cellValue($worksheet, $columnIndex, $rowNumber);
            }

            // Skip rows where every mapped cell is blank — trailing empty rows
            // are common in hand-maintained spreadsheets and are not errors.
            if (collect($values)->every(static fn (mixed $value): bool => $value === null)) {
                continue;
            }

            $rows[] = ['__row' => $rowNumber] + $values;
        }

        return [$foundColumns, $rows];
    }

    /**
     * Read one cell as a trimmed string, or null when empty. Excel date serials
     * are converted to Y-m-d so downstream date rules see a parseable value.
     *
     * $columnLetter is the spreadsheet column key ("A", "B", …) as handed out
     * by the header cell iterator.
     */
    private function cellValue(Worksheet $worksheet, string $columnLetter, int $rowNumber): ?string
    {
        $cell = $worksheet->getCell($columnLetter.$rowNumber);

        $value = $cell->getValue();

        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @throws InvalidStructureWorkbookException
     */
    private function load(UploadedFile $file): Spreadsheet
    {
        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(false);

            return $reader->load($file->getRealPath());
        } catch (ReaderException|Throwable) {
            throw new InvalidStructureWorkbookException(
                __('organization-structure-import.errors.invalid_excel_format'),
            );
        }
    }
}
