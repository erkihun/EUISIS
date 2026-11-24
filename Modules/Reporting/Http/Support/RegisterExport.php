<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Support;

use Carbon\CarbonImmutable;
use Closure;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Queries\ComplianceQuery;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes the compliance register as CSV or Excel from the same rows the page
 * shows.
 *
 * Formula injection: an institution or officer name beginning with =, +, -
 * or @ would be evaluated by a spreadsheet. CSV rows pass through the
 * application's csv_safe_row(); Excel cells are written explicitly as text
 * (never as formulas), which is why no cell here is set with setCellValue().
 */
final class RegisterExport
{
    private const COLUMNS = [
        'institution_code', 'institution', 'work_unit', 'responsible_officer', 'report_type', 'period', 'original_deadline', 'effective_deadline',
        'first_submission', 'workflow', 'timeliness', 'timeliness_original', 'days_overdue', 'applicability', 'reviewer', 'review_deadline',
    ];

    /** An export larger than this is refused rather than truncated silently; narrow the filters instead. */
    public const MAX_ROWS = 50000;

    /**
     * @param  array<string, string>  $filters
     * @param  Closure(int): void  $done  called with the number of rows written
     */
    public function download(ComplianceQuery $compliance, ActorContext $actor, array $filters, string $format, string $language, Closure $done): Response
    {
        abort_if($compliance->base($actor, $filters)->count() > self::MAX_ROWS, 422, (string) trans('reporting::export.too_many_rows', ['max' => self::MAX_ROWS], $language));
        $header = array_map(fn (string $column): string => (string) trans("reporting::export.columns.{$column}", [], $language), self::COLUMNS);
        $name = 'reporting-compliance-'.now('Africa/Addis_Ababa')->format('Ymd-Hi');
        $line = fn (array $row): array => $this->line($row, $language);

        if ($format === 'xlsx') {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet();
            $number = 1;
            $write = function (array $cells) use ($sheet, &$number): void {
                foreach (array_values($cells) as $index => $cell) {
                    $sheet->setCellValueExplicit([$index + 1, $number], (string) $cell, DataType::TYPE_STRING);
                }
                $number++;
            };
            $write($header);
            $compliance->eachRow($actor, $filters, fn (array $row) => $write($line($row)));
            $done($number - 2);
            $path = tempnam(sys_get_temp_dir(), 'rptx');
            (new Xlsx($book))->save($path);
            $book->disconnectWorksheets();

            return response()->download($path, "{$name}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
        }

        return response()->streamDownload(function () use ($compliance, $actor, $filters, $header, $line, $done): void {
            $out = fopen('php://output', 'w');
            // UTF-8 byte order mark, so Excel reads Amharic correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, csv_safe_row($header));
            $rows = 0;
            $compliance->eachRow($actor, $filters, function (array $row) use ($out, $line, &$rows): void {
                fputcsv($out, csv_safe_row($line($row)));
                $rows++;
            });
            fclose($out);
            $done($rows);
        }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function line(array $row, string $language): array
    {
        $pick = fn (array $pair, string $en, string $am): string => (string) ($language === 'am' ? ($pair[$am] ?: $pair[$en]) : ($pair[$en] ?: $pair[$am]));
        $local = fn (?string $iso): string => $iso === null ? '' : CarbonImmutable::parse($iso)->setTimezone('Africa/Addis_Ababa')->format('Y-m-d H:i').' EAT';
        $word = fn (string $group, ?string $value): string => $value === null ? '' : (string) trans("reporting::export.values.{$group}.{$value}", [], $language);

        return [
            (string) $row['organization']['code'],
            $pick($row['organization'], 'name_en', 'name_am'),
            ($row['unit'] ?? null) === null ? '' : $pick($row['unit'], 'name_en', 'name_am'),
            (string) ($row['accountable_name'] ?? $row['preparer_name'] ?? ''),
            $pick($row['report_type'], 'name_en', 'name_am'),
            $pick($row['period'], 'label_en', 'label_am'),
            $local($row['original_due_at']),
            $local($row['effective_due_at']),
            $local($row['first_submitted_at']),
            $word('workflow', $row['workflow']),
            $word('timeliness', $row['timeliness']),
            $word('timeliness', $row['timeliness_original']),
            (string) $row['days_overdue'],
            $word('applicability', $row['applicability']),
            (string) ($row['reviewer_name'] ?? ''),
            $local($row['review_due_at']),
        ];
    }
}
