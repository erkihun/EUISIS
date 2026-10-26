<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ExportRun;
use App\Models\User;
use App\Reports\ReportCatalog;
use App\Reports\ReportRunner;
use App\Services\AuditWriter;
use App\Services\CoreSettings;
use App\Services\OrganizationScope;
use App\Support\CoreDates;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Throwable;

/**
 * Writes one export (ADR 0011) in the background, always: the requester's
 * scope and permissions are applied as of now, the file goes to the private
 * exports disk, and the row count, checksum and outcome are recorded and
 * audited. A report larger than reports.max_export_rows is refused — narrow
 * the filters.
 */
class GenerateExport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public readonly string $exportRunId) {}

    public function handle(ReportCatalog $catalog, ReportRunner $runner, AuditWriter $audit, CoreSettings $settings, OrganizationScope $scope): void
    {
        $run = ExportRun::query()->findOrFail($this->exportRunId);
        $user = User::query()->findOrFail($run->requested_by);
        $run->update(['status' => 'running', 'started_at' => now()]);
        $scope->clearCache();
        $path = 'exports/'.$run->id.'.'.$run->format;
        $disk = Storage::disk('exports');
        $local = tempnam(sys_get_temp_dir(), 'core-export-');

        try {
            $columns = $runner->columns($run->report, $user, $run->columns, $run->include_sensitive);
            $query = $catalog->query($run->report, $user, (array) $run->filters);
            $max = $settings->int('reports.max_export_rows');
            $count = (clone $query)->getCountForPagination();
            if ($count > $max) {
                throw new \RuntimeException("The report has {$count} rows; exports are limited to {$max}. Narrow the filters.");
            }
            $headings = array_map(fn (array $c): string => $run->language === 'am' ? $c[1] : $c[0], $columns);
            $rows = (function () use ($query, $runner, $run, $columns) {
                foreach ($query->lazy(1000) as $row) {
                    yield $runner->row($run->report, $row, $columns, $run->language);
                }
            })();
            $written = match ($run->format) {
                'csv' => $this->csv($local, $headings, $rows),
                'xlsx' => $this->xlsx($local, $headings, $rows),
                'html' => $this->html($local, $run, $headings, $rows, $user),
            };
            $stream = fopen($local, 'rb');
            $disk->put($path, $stream);
            is_resource($stream) && fclose($stream);
            $run->update(['status' => 'completed', 'row_count' => $written, 'file_disk' => 'exports', 'file_path' => $path, 'file_size' => filesize($local),
                'checksum' => hash_file('sha256', $local), 'completed_at' => now(), 'expires_at' => now()->addDays($settings->int('reports.export_retention_days'))]);
            $audit->writeFor('report.export_completed', $user, 'ExportRun', (string) $run->id, null, null,
                ['report' => $run->report, 'format' => $run->format, 'rows' => $written, 'filters' => $run->filters, 'columns' => array_keys($columns), 'sensitive' => $run->include_sensitive]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'completed_at' => now()]);
            $audit->writeFor('report.export_failed', $user, 'ExportRun', (string) $run->id, null, null, ['report' => $run->report, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        } finally {
            @unlink($local);
        }
    }

    /** @param iterable<array<string, string>> $rows */
    private function csv(string $file, array $headings, iterable $rows): int
    {
        $out = fopen($file, 'wb');
        fwrite($out, "\u{FEFF}");
        fputcsv($out, $headings, ',', '"', '');
        $count = 0;
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn (string $v): string => ReportRunner::safeCell($v), array_values($row)), ',', '"', '');
            $count++;
        }
        fclose($out);

        return $count;
    }

    /** @param iterable<array<string, string>> $rows */
    private function xlsx(string $file, array $headings, iterable $rows): int
    {
        $writer = new XlsxWriter;
        $writer->openToFile($file);
        $writer->addRow(Row::fromValues(array_values($headings)));
        $count = 0;
        foreach ($rows as $row) {
            // Strings stay strings: OpenSpout writes no formulas, and the leading-character guard covers re-saving as CSV.
            $writer->addRow(Row::fromValues(array_map(fn (string $v): string => ReportRunner::safeCell($v), array_values($row))));
            $count++;
        }
        $writer->close();

        return $count;
    }

    /**
     * A printable page (print or save as PDF from the browser, which renders
     * Amharic with the system's Ethiopic fonts).
     *
     * @param  iterable<array<string, string>>  $rows
     */
    private function html(string $file, ExportRun $run, array $headings, iterable $rows, User $user): int
    {
        $am = $run->language === 'am';
        $title = e(__('oversight.reports.'.$run->report, [], $run->language));
        $out = fopen($file, 'wb');
        fwrite($out, '<!doctype html><html lang="'.e($run->language).'"><head><meta charset="utf-8"><title>'.$title.'</title><style>'
            ."body{font-family:'Noto Sans Ethiopic','Nyala','Segoe UI',sans-serif;font-size:11px;margin:16px}table{border-collapse:collapse;width:100%}"
            .'th,td{border:1px solid #999;padding:3px 5px;text-align:left;vertical-align:top}thead{display:table-header-group}tr{page-break-inside:avoid}'
            .'h1{font-size:16px;margin:0 0 4px}p{margin:0 0 10px;color:#444}@page{size:A4 landscape;margin:12mm}</style></head><body>');
        fwrite($out, '<h1>'.$title.'</h1><p>'.e(($am ? 'የተዘጋጀው በ' : 'Prepared by ').$user->name.' · '.CoreDates::display(now(), $run->language, true)).'</p>');
        fwrite($out, '<table><thead><tr>'.implode('', array_map(fn (string $h): string => '<th>'.e($h).'</th>', $headings)).'</tr></thead><tbody>');
        $count = 0;
        foreach ($rows as $row) {
            fwrite($out, '<tr>'.implode('', array_map(fn (string $v): string => '<td>'.e($v).'</td>', array_values($row))).'</tr>');
            $count++;
        }
        fwrite($out, '</tbody></table></body></html>');
        fclose($out);

        return $count;
    }
}
