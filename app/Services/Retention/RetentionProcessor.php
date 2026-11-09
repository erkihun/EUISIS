<?php

declare(strict_types=1);

namespace App\Services\Retention;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Services\Audit\AuditLogSealer;
use App\Support\Retention\RetentionMatrix;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Grievance\Contracts\ReadModels\GrievanceRecord;

/**
 * Applies an approved retention decision to a record class the application
 * can process (audit, verification and API logs). Nothing happens unless
 * RetentionMatrix says the class is AUTHORIZED. "archive" writes the expired
 * rows to the archive disk (gzip NDJSON per batch, listed with a SHA-256 in
 * the class manifest) before removing them; "purge_when_authorized" removes
 * them. Every run that changes data leaves an audit row naming the approval.
 */
final readonly class RetentionProcessor
{
    public function __construct(
        private RetentionMatrix $matrix,
        private AuditLogSealer $sealer,
        private WriteAuditLogAction $audit,
    ) {}

    /**
     * @return list<array{table: string, column: string, rows: int, files: int}>|null null when the class is not authorized
     */
    public function process(string $class, bool $dryRun = false): ?array
    {
        if (! $this->matrix->authorized($class)) {
            return null;
        }

        $decision = $this->matrix->for($class);
        $cutoff = now()->subDays((int) $decision['days']);
        $results = [];

        foreach ($this->targets($class) as [$table, $key, $column, $constraint]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $results[] = ['table' => $table, 'column' => $column] + ($dryRun
                ? ['rows' => $this->expired($table, $column, $cutoff, $constraint)->count(), 'files' => 0]
                : $this->apply($class, $decision, $table, $key, $column, $cutoff, $constraint));
        }

        if (! $dryRun) {
            if ($class === 'audit_logs' && $this->sealer->available()) {
                // The cutoff day is partly removed: its seal goes with it.
                $this->sealer->forgetThrough($cutoff->toDateString());
            }
            $this->audit->execute(AuditEventType::RetentionCleanup, null, newValues: [
                'class' => $class, 'action' => $decision['action'], 'period_days' => $decision['days'],
                'approval' => $decision['approval'], 'cutoff' => $cutoff->toIso8601String(), 'results' => $results,
            ], reason: 'Approved retention schedule applied.');
        }

        return $results;
    }

    /** Remove rows older than the cutoff in key order, in batches (also used for operational housekeeping). */
    public function deleteExpired(string $table, string $key, string $column, Carbon $cutoff, ?Closure $constraint = null): int
    {
        $deleted = 0;
        do {
            $ids = $this->expired($table, $column, $cutoff, $constraint)->orderBy($key)->limit($this->batch())->pluck($key);
            $removed = $ids->isEmpty() ? 0 : DB::table($table)->whereIn($key, $ids->all())->delete();
            $deleted += $removed;
        } while ($removed > 0);

        return $deleted;
    }

    /** @param (Closure(Builder): mixed)|null $constraint */
    public function expired(string $table, string $column, Carbon $cutoff, ?Closure $constraint = null): Builder
    {
        return DB::table($table)->whereNotNull($column)->where($column, '<', $cutoff)->when($constraint, $constraint);
    }

    /**
     * @param  array{action: string, days: ?int, approval: ?string}  $decision
     * @return array{rows: int, files: int}
     */
    private function apply(string $class, array $decision, string $table, string $key, string $column, Carbon $cutoff, ?Closure $constraint): array
    {
        if ($decision['action'] !== 'archive') {
            return ['rows' => $this->deleteExpired($table, $key, $column, $cutoff, $constraint), 'files' => 0];
        }

        $disk = Storage::disk((string) config('retention.archive_disk', 'local'));
        $rows = 0;
        $files = 0;
        do {
            $batch = $this->expired($table, $column, $cutoff, $constraint)->orderBy($key)->limit($this->batch())->get();
            if ($batch->isEmpty()) {
                break;
            }
            $lines = $batch->map(fn (object $row): string => (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->implode("\n")."\n";
            $path = sprintf('retention-archive/%s/%s-%s-%04d.ndjson.gz', $class, now()->format('Ymd-His'), $table, ++$files);
            $disk->put($path, (string) gzencode($lines, 9));
            $disk->append("retention-archive/{$class}/manifest.ndjson", (string) json_encode([
                'file' => $path, 'table' => $table, 'rows' => $batch->count(), 'sha256' => hash('sha256', $lines),
                'cutoff' => $cutoff->toIso8601String(), 'approval' => $decision['approval'], 'archived_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES));
            // Removed only after the archive file is stored.
            $rows += DB::table($table)->whereIn($key, $batch->pluck($key)->all())->delete();
        } while ($batch->count() === $this->batch());

        return ['rows' => $rows, 'files' => $files];
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: (Closure(Builder): mixed)|null}> */
    private function targets(string $class): array
    {
        return match ($class) {
            'audit_logs' => [
                // Audit rows about a grievance stay until the case is archived and off legal hold.
                ['audit_logs', 'id', 'created_at', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->whereNull('auditable_type')
                    ->orWhere('auditable_type', '!=', (new GrievanceRecord)->getMorphClass())
                    ->orWhereNotIn('auditable_id', DB::table('grievances')
                        // auditable_id is a string column (it holds integer and uuid keys).
                        ->whereNull('archived_at')->orWhere('legal_hold', true)->selectRaw('CAST(id AS VARCHAR(64))')))],
                ['security_events', 'id', 'created_at', null],
            ],
            'verification_logs' => [
                ['card_verifications', 'id', 'created_at', null],
                ['nfc_verification_logs', 'id', 'occurred_at', null],
            ],
            'api_logs' => [
                ['api_request_logs', 'id', 'requested_at', null],
            ],
            default => [],
        };
    }

    private function batch(): int
    {
        return max(1, (int) config('retention.batch_size', 1000));
    }
}
