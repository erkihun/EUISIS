<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ManageImports;
use App\Imports\ImportReader;
use App\Imports\OrganizationImport;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\CoreSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads an uploaded import row by row and checks every row with the Core's
 * rules (ADR 0009); rows are written in chunks, so a large file never sits in
 * memory. A retry starts the batch's rows again from the file; a batch that is
 * no longer waiting for validation is left alone.
 */
final class ValidateImportBatch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    private const CHUNK = 500;

    public function __construct(public readonly string $batchId) {}

    public function uniqueId(): string
    {
        return $this->batchId;
    }

    public function handle(AuditWriter $audit, CoreSettings $settings): void
    {
        $batch = DB::transaction(function (): ?ImportBatch {
            $batch = ImportBatch::query()->lockForUpdate()->find($this->batchId);
            if ($batch === null || ! in_array($batch->status, [ImportBatch::UPLOADED, ImportBatch::VALIDATING], true)) {
                return null;
            }
            $batch->forceFill(['status' => ImportBatch::VALIDATING])->save();

            return $batch;
        });
        if ($batch === null) {
            return;
        }
        ImportRow::query()->where('import_batch_id', $batch->id)->delete();
        $uploader = User::query()->findOrFail($batch->uploaded_by);
        $definition = ManageImports::definition($batch->type);
        $limit = $settings->int('files.import_max_rows');
        $disk = Storage::disk($batch->storage_disk);
        $extension = pathinfo($batch->storage_path, PATHINFO_EXTENSION);

        $buffer = [];
        $total = 0;
        foreach (ImportReader::rows($disk->path($batch->storage_path), $extension) as $number => $cells) {
            if (++$total > $limit) {
                $this->markFailed($batch, $audit, ['code' => 'TOO_MANY_ROWS', 'message' => "The file has more than {$limit} rows; split it."]);

                return;
            }
            $row = $definition->normalize($cells);
            $errors = $definition->validate($row, $number, $uploader);
            $buffer[] = [
                'import_batch_id' => $batch->id, 'row_number' => $number, 'payload' => Crypt::encryptString((string) json_encode($row)),
                'status' => $errors === [] ? ImportRow::VALID : ImportRow::INVALID, 'errors' => $errors === [] ? null : json_encode($errors),
                'created_at' => now(), 'updated_at' => now(),
            ];
            if (count($buffer) >= self::CHUNK) {
                ImportRow::query()->insert($buffer);
                $buffer = [];
            }
        }
        if ($buffer !== []) {
            ImportRow::query()->insert($buffer);
        }
        if ($total === 0) {
            $this->markFailed($batch, $audit, ['code' => 'EMPTY_FILE', 'message' => 'The file has no rows under its header.']);

            return;
        }
        if ($definition instanceof OrganizationImport) {
            foreach ($definition->unresolvedParents() as $number => $error) {
                $row = ImportRow::query()->where('import_batch_id', $batch->id)->where('row_number', $number)->first();
                $row?->forceFill(['status' => ImportRow::INVALID, 'errors' => [...($row->errors ?? []), $error]])->save();
            }
        }

        $counts = ImportRow::query()->where('import_batch_id', $batch->id)->toBase()->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status');
        $valid = (int) ($counts[ImportRow::VALID] ?? 0);
        $invalid = (int) ($counts[ImportRow::INVALID] ?? 0);
        $codes = ImportRow::query()->where('import_batch_id', $batch->id)->where('status', ImportRow::INVALID)->pluck('errors')
            ->flatMap(fn ($errors): array => array_column(is_array($errors) ? $errors : (array) json_decode((string) $errors, true), 'code'))->countBy()->sortDesc()->all();
        $batch->forceFill([
            'status' => $invalid === 0 ? ImportBatch::READY : ImportBatch::REQUIRES_ATTENTION,
            'total_rows' => $total, 'valid_rows' => $valid, 'invalid_rows' => $invalid, 'validated_at' => now(),
            'error_summary' => $codes === [] ? null : ['codes' => $codes],
        ])->save();
        $audit->writeFor('import.validated', $uploader, 'ImportBatch', (string) $batch->id, $batch->scope_organization_id, null,
            ['total_rows' => $total, 'valid_rows' => $valid, 'invalid_rows' => $invalid]);
    }

    public function failed(Throwable $exception): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        if ($batch !== null && ! in_array($batch->status, [ImportBatch::COMPLETED, ImportBatch::CANCELLED], true)) {
            $this->markFailed($batch, app(AuditWriter::class), ['code' => 'VALIDATION_FAILED', 'message' => 'The file could not be read.']);
            report($exception);
        }
    }

    /** @param array{code: string, message: string} $summary */
    private function markFailed(ImportBatch $batch, AuditWriter $audit, array $summary): void
    {
        ImportRow::query()->where('import_batch_id', $batch->id)->delete();
        $batch->forceFill(['status' => ImportBatch::FAILED, 'error_summary' => $summary, 'completed_at' => now()])->save();
        $audit->writeFor('import.failed', null, 'ImportBatch', (string) $batch->id, $batch->scope_organization_id, null, $summary);
    }
}
