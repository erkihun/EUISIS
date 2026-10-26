<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ManageImports;
use App\Imports\ImportDefinition;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Writes an approved import through the domain actions, as the approver
 * (ADR 0009). Each row is locked, written and marked processed (with the
 * record it produced) in one transaction, so a retried job skips what is done
 * and never creates an employee, organization, position or placement twice.
 * A row the domain refuses now (a seat taken since validation, say) is marked
 * failed with the reason and the batch goes on.
 */
final class ProcessImportBatch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3600;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $batchId) {}

    public function uniqueId(): string
    {
        return $this->batchId;
    }

    public function handle(AuditWriter $audit): void
    {
        $batch = DB::transaction(function (): ?ImportBatch {
            $batch = ImportBatch::query()->lockForUpdate()->find($this->batchId);
            if ($batch === null || ! in_array($batch->status, [ImportBatch::APPROVED, ImportBatch::PROCESSING], true)) {
                return null;
            }
            $batch->forceFill(['status' => ImportBatch::PROCESSING, 'started_at' => $batch->started_at ?? now()])->save();

            return $batch;
        });
        if ($batch === null) {
            return;
        }
        $actor = User::query()->findOrFail($batch->approved_by);
        $definition = ManageImports::definition($batch->type);
        if ($batch->processed_rows === 0 && $batch->failed_rows === 0) {
            $audit->writeFor('import.started', $actor, 'ImportBatch', (string) $batch->id, $batch->scope_organization_id, null, ['valid_rows' => $batch->valid_rows]);
        }

        ImportRow::query()->where('import_batch_id', $batch->id)->where('status', ImportRow::VALID)->orderBy('id')
            ->chunkById(200, function ($rows) use ($definition, $batch, $actor): void {
                foreach ($rows as $row) {
                    $this->processRow($row->id, $definition, $batch, $actor);
                }
            });
        rescue(fn () => $definition->finish($batch->fresh(), $actor), function (Throwable $exception) use ($batch): void {
            $batch->refresh()->forceFill(['error_summary' => array_merge($batch->error_summary ?? [], ['finish' => $exception->getMessage()])])->save();
        });

        $counts = ImportRow::query()->where('import_batch_id', $batch->id)->toBase()->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status');
        $batch->refresh()->forceFill([
            'status' => ImportBatch::COMPLETED,
            'processed_rows' => (int) ($counts[ImportRow::PROCESSED] ?? 0),
            'failed_rows' => (int) ($counts[ImportRow::FAILED] ?? 0),
            'completed_at' => now(),
        ])->save();
        $audit->writeFor('import.completed', $actor, 'ImportBatch', (string) $batch->id, $batch->scope_organization_id, null,
            ['processed_rows' => $batch->processed_rows, 'failed_rows' => $batch->failed_rows, 'hierarchy_version_id' => $batch->hierarchy_version_id]);
    }

    public function failed(Throwable $exception): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        if ($batch !== null && $batch->status !== ImportBatch::COMPLETED) {
            $batch->forceFill(['status' => ImportBatch::FAILED, 'error_summary' => array_merge($batch->error_summary ?? [], ['code' => 'PROCESSING_FAILED']), 'completed_at' => now()])->save();
            app(AuditWriter::class)->writeFor('import.failed', null, 'ImportBatch', (string) $batch->id, $batch->scope_organization_id, null, ['code' => 'PROCESSING_FAILED']);
            report($exception);
        }
    }

    private function processRow(int $rowId, ImportDefinition $definition, ImportBatch $batch, User $actor): void
    {
        try {
            DB::transaction(function () use ($rowId, $definition, $batch, $actor): void {
                $row = ImportRow::query()->lockForUpdate()->find($rowId);
                if ($row === null || $row->status !== ImportRow::VALID) {
                    return; // done by an earlier attempt
                }
                [$type, $id] = $definition->process($row->payload, $batch, $actor);
                $row->forceFill(['status' => ImportRow::PROCESSED, 'result_type' => $type, 'result_id' => $id, 'processed_at' => now()])->save();
            });
        } catch (ValidationException|HttpResponseException|HttpExceptionInterface $refused) {
            $message = match (true) {
                $refused instanceof ValidationException => (string) collect($refused->errors())->flatten()->first(),
                $refused instanceof HttpResponseException => (string) (json_decode((string) $refused->getResponse()->getContent(), true)['message'] ?? 'Refused.'),
                default => $refused->getMessage() !== '' ? $refused->getMessage() : 'Not allowed.',
            };
            ImportRow::query()->whereKey($rowId)->where('status', ImportRow::VALID)->update([
                'status' => ImportRow::FAILED,
                'errors' => json_encode([['field' => '', 'code' => 'REFUSED_ON_IMPORT', 'message' => $message]]),
                'updated_at' => now(),
            ]);
        }
    }
}
