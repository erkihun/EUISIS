<?php

declare(strict_types=1);

namespace App\Exports\DailyActivity;

use App\Services\Calendar\LocalizedDateService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * One Daily Activity report as a flat sheet (xlsx / csv), and the same rows
 * for the PDF view.
 *
 * Dates are written in the reader's calendar (Ethiopian for Amharic,
 * Gregorian for English) because an export is read by people; the ISO value
 * stays the source of truth in the database. Strings are bound explicitly as
 * text so a narrative beginning with "=" can never execute as a formula.
 */
class DailyActivityReportExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings
{
    private const DATE_COLUMNS = ['date'];

    private const DATETIME_COLUMNS = ['submitted_at', 'reviewed_at'];

    private const STATUS_COLUMNS = ['status', 'day_status', 'progress_status', 'source'];

    /** @param array{columns: array<int, string>, rows: array<int, array<string, mixed>>} $report */
    public function __construct(
        private readonly array $report,
        private readonly LocalizedDateService $dates,
    ) {}

    public function headings(): array
    {
        return array_map(static fn (string $column): string => __("daily-activities.columns.{$column}"), $this->report['columns']);
    }

    public function array(): array
    {
        return array_map(function (array $row): array {
            $cells = [];

            foreach ($this->report['columns'] as $column) {
                $cells[] = $this->cell($column, $row[$column] ?? null);
            }

            return $cells;
        }, $this->report['rows']);
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    private function cell(string $column, mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }

        if (in_array($column, self::DATE_COLUMNS, true)) {
            return (string) $this->dates->displayDate((string) $value);
        }

        if (in_array($column, self::DATETIME_COLUMNS, true)) {
            return (string) $this->dates->displayDateTime((string) $value);
        }

        if (is_bool($value)) {
            return __($value ? 'daily-activities.values.yes' : 'daily-activities.values.no');
        }

        if (in_array($column, self::STATUS_COLUMNS, true)) {
            $key = "daily-activities.values.{$value}";
            $statusKey = "daily-activities.status.{$value}";

            return trans()->has($key) ? __($key) : (trans()->has($statusKey) ? __($statusKey) : (string) $value);
        }

        return $value;
    }
}
