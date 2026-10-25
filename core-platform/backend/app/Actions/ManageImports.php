<?php

declare(strict_types=1);

namespace App\Actions;

use App\Imports\AssignmentImport;
use App\Imports\EmployeeImport;
use App\Imports\ImportDefinition;
use App\Imports\ImportReader;
use App\Imports\OrganizationImport;
use App\Imports\PositionImport;
use App\Jobs\ProcessImportBatch;
use App\Jobs\ValidateImportBatch;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CoreSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The business import lifecycle (ADR 0009): upload → (queued) validation and
 * duplicate checks → preview and error review → approval by a second person
 * → (queued) processing through the domain actions → completed or failed.
 * Nothing is written before approval; nothing is processed synchronously in a
 * web request; the source file stays private for the audit trail.
 */
final readonly class ManageImports
{
    public const DISK = 'imports';

    /** File types accepted, by extension and by the content type the server detects. */
    private const FILES = [
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];

    public function __construct(private AuditWriter $audit, private CoreSettings $settings) {}

    public static function definition(string $type): ImportDefinition
    {
        return match ($type) {
            'organizations' => app(OrganizationImport::class),
            'positions' => app(PositionImport::class),
            'employees' => app(EmployeeImport::class),
            'assignments' => app(AssignmentImport::class),
            default => throw ValidationException::withMessages(['type' => 'Choose what the file imports.']),
        };
    }

    public function upload(string $type, UploadedFile $file, User $actor): ImportBatch
    {
        Gate::forUser($actor)->authorize('create', [ImportBatch::class, $type]);
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $maxKb = $this->settings->int('files.import_max_kb');
        if (! $file->isValid() || $file->getSize() <= 0) {
            throw ValidationException::withMessages(['file' => 'The file is empty or did not upload completely.']);
        }
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages(['file' => "The file is larger than {$maxKb} KB."]);
        }
        if (! isset(self::FILES[$extension]) || ! in_array($mime, self::FILES[$extension], true) || ($extension === 'xlsx' && ! $this->isWorkbook($file))) {
            throw ValidationException::withMessages(['file' => 'Upload a CSV file or an Excel workbook (.xlsx) without macros.']);
        }
        // An unreadable file is the uploader's problem ("lacks these columns"); anything else (a missing class, a
        // broken disk) is reported, so it is not hidden behind that message (ADR 0012).
        $header = rescue(fn () => ImportReader::header($file->getRealPath(), $extension), [], fn (Throwable $e): bool => ! str_starts_with($e::class, 'OpenSpout\\'));
        $missing = array_diff(self::definition($type)->columns(), $header);
        if ($missing !== []) {
            throw ValidationException::withMessages(['file' => 'The file lacks these columns: '.implode(', ', $missing).'. Start from the template.']);
        }

        return DB::transaction(function () use ($type, $file, $extension, $actor): ImportBatch {
            $id = (string) Str::uuid7();
            $path = "{$id}.{$extension}";
            Storage::disk(self::DISK)->putFileAs('', $file, $path);
            DB::afterRollBack(fn () => Storage::disk(self::DISK)->delete($path));
            $batch = new ImportBatch;
            $batch->forceFill([
                'id' => $id, 'type' => $type, 'status' => ImportBatch::UPLOADED,
                'source_filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                'storage_disk' => self::DISK, 'storage_path' => $path, 'checksum' => hash_file('sha256', $file->getRealPath()),
                'file_size' => (int) $file->getSize(), 'uploaded_by' => $actor->getKey(),
                'scope_organization_id' => $actor->isCityWide() ? null : $actor->scope_organization_id,
            ])->save();
            $this->audit->writeFor('import.uploaded', $actor, 'ImportBatch', $id, $batch->scope_organization_id, null,
                ['type' => $type, 'file' => $batch->source_filename, 'checksum' => $batch->checksum, 'file_size' => $batch->file_size]);
            DB::afterCommit(fn () => ValidateImportBatch::dispatch($id));

            return $batch;
        });
    }

    public function approve(ImportBatch $batch, bool $acknowledgeInvalid, User $actor): ImportBatch
    {
        return DB::transaction(function () use ($batch, $acknowledgeInvalid, $actor): ImportBatch {
            $batch = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            Gate::forUser($actor)->authorize('approve', $batch);
            if ($batch->valid_rows === 0) {
                throw ValidationException::withMessages(['batch' => 'No row is valid: correct the file and upload it again.']);
            }
            if ($batch->invalid_rows > 0 && ! $acknowledgeInvalid) {
                throw ValidationException::withMessages(['acknowledge_invalid' => 'Confirm that the '.$batch->invalid_rows.' invalid rows will not be imported.']);
            }
            $batch->forceFill(['status' => ImportBatch::APPROVED, 'approved_by' => $actor->getKey(), 'approved_at' => now()])->save();
            $this->audit->writeFor('import.approved', $actor, 'ImportBatch', (string) $batch->id, $batch->scope_organization_id, null,
                ['valid_rows' => $batch->valid_rows, 'invalid_rows' => $batch->invalid_rows, 'checksum' => $batch->checksum]);
            DB::afterCommit(fn () => ProcessImportBatch::dispatch((string) $batch->id));

            return $batch;
        });
    }

    public function cancel(ImportBatch $batch, string $reason, User $actor): ImportBatch
    {
        return DB::transaction(function () use ($batch, $reason, $actor): ImportBatch {
            $batch = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            Gate::forUser($actor)->authorize('cancel', $batch);
            $batch->forceFill(['status' => ImportBatch::CANCELLED, 'cancelled_by' => $actor->getKey(), 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $this->audit->writeFor('import.cancelled', $actor, 'ImportBatch', (string) $batch->id, $batch->scope_organization_id, null, ['status' => ImportBatch::CANCELLED], $reason);

            return $batch;
        });
    }

    /** A blank template with the columns, as CSV. */
    public function template(string $type): StreamedResponse
    {
        $columns = self::definition($type)->allColumns();

        return response()->streamDownload(function () use ($columns): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, $columns, ',', '"', '');
            fclose($out);
        }, "core-import-{$type}-template.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The rows that were not imported, with every error, as CSV. A cell that a
     * spreadsheet would run as a formula is neutralized, and a national ID is
     * never written out.
     */
    public function errorReport(ImportBatch $batch, User $actor): StreamedResponse
    {
        $columns = self::definition($batch->type)->allColumns();
        $this->audit->writeFor('import.error_report_downloaded', $actor, 'ImportBatch', (string) $batch->id, $batch->scope_organization_id);

        return response()->streamDownload(function () use ($batch, $columns): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['row', 'field', 'code', 'message', ...$columns], ',', '"', '');
            ImportRow::query()->where('import_batch_id', $batch->id)->whereIn('status', [ImportRow::INVALID, ImportRow::FAILED])
                ->orderBy('row_number')->chunk(500, function ($rows) use ($out, $columns): void {
                    foreach ($rows as $row) {
                        $values = array_map(fn (string $column): string => $column === 'national_id' && ($row->payload[$column] ?? null) !== null ? '***' : (string) ($row->payload[$column] ?? ''), $columns);
                        foreach ($row->errors ?? [] as $error) {
                            fputcsv($out, array_map([self::class, 'safeCell'], [(string) $row->row_number, $error['field'] ?? '', $error['code'] ?? '', $error['message'] ?? '', ...$values]), ',', '"', '');
                        }
                    }
                });
            fclose($out);
        }, "core-import-{$batch->id}-errors.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A spreadsheet must not read a cell as a formula (CSV injection). */
    public static function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /** An .xlsx is a zip with a workbook and no macro project. */
    private function isWorkbook(UploadedFile $file): bool
    {
        $zip = new \ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            return false;
        }
        $ok = $zip->locateName('xl/workbook.xml') !== false && $zip->locateName('xl/vbaProject.bin') === false;
        $zip->close();

        return $ok;
    }
}
