<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Validator;

/**
 * Evidence the backup tool cannot report itself, from the ops runner's published report: the
 * operation journal (verification, restore tests, logical archives), control attestations and
 * repository capacity. Optional: without it those items read as not recorded, never as passed.
 */
class BackupEvidenceReader
{
    public function __construct(private BackupReportReader $reader) {}

    /** @return array{status: string, reason_code: ?string, operations: list<array>, controls: array<string, bool>, controls_checked_at: ?int, capacity: array<int, float|int>} */
    public function read(): array
    {
        $empty = ['status' => 'NOT_AVAILABLE', 'reason_code' => null, 'operations' => [], 'controls' => [], 'controls_checked_at' => null, 'capacity' => []];
        if (! config('backup.enabled')) {
            return [...$empty, 'reason_code' => 'NOT_CONFIGURED'];
        }
        try {
            $raw = $this->reader->read();
        } catch (BackupReportException $e) {
            return [...$empty, 'reason_code' => $e->reasonCode];
        }
        $invalid = [...$empty, 'reason_code' => 'INVALID_REPORT'];
        $validator = Validator::make($raw, [
            'observed_at' => ['required', 'integer', 'min:1'],
            'operations' => ['present', 'array', 'max:1000'],
            'operations.*.id' => ['required', 'uuid', 'distinct'],
            'operations.*.type' => ['required', 'in:FULL_BACKUP,DIFFERENTIAL_BACKUP,INCREMENTAL_BACKUP,LOGICAL_BACKUP,VERIFY,RESTORE_TEST,RETENTION_CLEANUP'],
            'operations.*.status' => ['required', 'in:RUNNING,SUCCEEDED,FAILED'],
            'operations.*.repository' => ['nullable', 'integer', 'in:1,2'],
            'operations.*.started_at' => ['required', 'integer', 'min:1'],
            'operations.*.completed_at' => ['nullable', 'integer', 'min:1'],
            'operations.*.backup_reference' => ['nullable', 'regex:/^\d{8}-\d{6}F(?:_\d{8}-\d{6}[DI])?$/'],
            'operations.*.backup_size' => ['nullable', 'integer', 'min:0'],
            'operations.*.recovery_target' => ['nullable', 'integer', 'min:1'],
            'controls' => ['present', 'array'],
            'controls_checked_at' => ['nullable', 'integer', 'min:1'],
            'repositories' => ['nullable', 'array'],
            'repositories.*.id' => ['required', 'integer', 'in:1,2'],
            'repositories.*.usage_percent' => ['nullable', 'numeric', 'between:0,100'],
        ]);
        if ($validator->fails()) {
            return $invalid;
        }
        $observed = $raw['observed_at'];
        $operations = [];
        foreach ($raw['operations'] as $operation) {
            $completed = $operation['completed_at'] ?? null;
            if ($operation['started_at'] > $observed + 60 || ($completed !== null && ($completed < $operation['started_at'] || $completed > $observed + 60))
                || ($operation['status'] !== 'RUNNING' && $completed === null)
                // Only the logical dump lives outside the two pgBackRest repositories.
                || (($operation['type'] === 'LOGICAL_BACKUP') !== (($operation['repository'] ?? null) === null))) {
                return $invalid;
            }
            $safe = ['repository' => null, ...array_intersect_key($operation, array_flip(['id', 'type', 'status', 'repository', 'started_at', 'completed_at', 'backup_reference', 'backup_size', 'recovery_target']))];
            $safe['failure_summary'] = $safe['status'] === 'FAILED' ? 'OPERATION_FAILED: consult restricted operator logs.' : null;
            $operations[] = $safe;
        }
        $controls = [];
        foreach (BackupStatusService::CONTROLS as $control) {
            $controls[$control] = ($raw['controls'][$control] ?? null) === true;
        }
        // Capacity is a point-in-time reading: only trust it from a fresh report.
        $capacity = [];
        if (time() - $observed <= config('backup.report_stale_minutes') * 60) {
            foreach ($raw['repositories'] ?? [] as $repository) {
                if (isset($repository['usage_percent'])) {
                    $capacity[$repository['id']] = $repository['usage_percent'];
                }
            }
        }

        return ['status' => 'AVAILABLE', 'reason_code' => null, 'operations' => $operations, 'controls' => $controls,
            'controls_checked_at' => $raw['controls_checked_at'] ?? null, 'capacity' => $capacity];
    }
}
