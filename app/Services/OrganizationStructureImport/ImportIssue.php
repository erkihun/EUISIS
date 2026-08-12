<?php

declare(strict_types=1);

namespace App\Services\OrganizationStructureImport;

/**
 * One validation error or warning, anchored to the sheet and spreadsheet row
 * it came from so the preview UI can point the user at the offending cell.
 *
 * `row` is the 1-based row number as seen in Excel (header row included), so a
 * message about the first data row reports row 2. Sheet-level problems (a
 * missing sheet or column) carry a null row.
 */
final readonly class ImportIssue
{
    private function __construct(
        public StructureSheet $sheet,
        public ?int $row,
        public ?string $column,
        public string $message,
        public bool $isWarning,
    ) {}

    public static function error(StructureSheet $sheet, ?int $row, string $message, ?string $column = null): self
    {
        return new self($sheet, $row, $column, $message, false);
    }

    public static function warning(StructureSheet $sheet, ?int $row, string $message, ?string $column = null): self
    {
        return new self($sheet, $row, $column, $message, true);
    }

    /** @return array{sheet: string, row: int|null, column: string|null, message: string} */
    public function toArray(): array
    {
        return [
            'sheet' => $this->sheet->value,
            'row' => $this->row,
            'column' => $this->column,
            'message' => $this->message,
        ];
    }
}
