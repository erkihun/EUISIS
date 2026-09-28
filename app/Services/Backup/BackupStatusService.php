<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\BackupOperation;
use App\Services\Backup\Infrastructure\BackupInfrastructureAdapter;
use App\Services\Backup\Infrastructure\InfrastructureSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BackupStatusService
{
    public const CONTROLS = ['independent_copy', 'encrypted', 'private_storage', 'key_recovery', 'file_recovery', 'retention_configured', 'owner_assigned', 'alerts_tested', 'runbooks_reviewed'];

    public const REPOSITORIES = [1, 2];

    private const CACHE_KEY = 'backup.health.probes';

    private bool $probedNow = false;

    public function __construct(
        private BackupInfrastructureAdapter $adapter,
        private WalArchiveInspector $wal,
        private BackupEvidenceReader $evidence,
        private BackupHealthEvaluator $evaluator,
    ) {}

    public function status(bool $fresh = false, ?bool $enforce = null): array
    {
        return $this->health($fresh, $enforce)->toArray();
    }

    /** Health without backup inventory, operation history or process diagnostics. */
    public function summary(bool $fresh = false): array
    {
        $status = $this->status($fresh);
        $status['operations'] = [];
        $status['diagnostics'] = [];
        foreach ($status['repositories'] as &$repository) {
            unset($repository['backups']);
        }

        return $status;
    }

    /**
     * @param  bool  $fresh  bypass the short probe cache (explicit refresh, CLI, monitor)
     * @param  bool|null  $enforce  apply production rules; defaults to production or BACKUP_ENFORCE_PRODUCTION_RULES
     */
    public function health(bool $fresh = false, ?bool $enforce = null): BackupHealthStatus
    {
        $enforced = $enforce ?? (app()->isProduction() || (bool) config('backup.enforce_production_rules'));
        if ($this->policyErrors() !== []) {
            return $this->evaluator->evaluate(
                InfrastructureSnapshot::failure('INVALID_CONFIGURATION', $this->adapter->driver(), diagnostics: ['policy' => 'INVALID']),
                $this->wal->evaluate(null, 'NOT_CHECKED'),
                ['status' => 'NOT_AVAILABLE', 'reason_code' => 'NOT_CHECKED', 'operations' => [], 'controls' => [], 'controls_checked_at' => null, 'capacity' => []],
                [], $enforced, time());
        }
        $this->probedNow = false;
        $probes = $this->probes($fresh);
        $status = $this->evaluator->evaluate(InfrastructureSnapshot::fromArray($probes['infrastructure']), $probes['wal'], $probes['evidence'],
            $this->history(), $enforced, time());
        if ($this->probedNow) {
            $this->log($status);
        }

        return $status;
    }

    public function policyErrors(): array
    {
        $rules = [];
        foreach (['report_stale_minutes', 'stale_hours', 'critical_hours', 'full_stale_days', 'verify_stale_days', 'restore_test_days', 'wal_lag_seconds', 'wal_backlog_segments', 'alert_repeat_minutes', 'retention_full_count', 'logical_stale_days'] as $key) {
            $rules[$key] = ['required', 'integer', 'min:1'];
        }
        $rules['critical_hours'][] = 'gte:stale_hours';
        $rules['storage_warning_percent'] = ['required', 'integer', 'between:1,99'];
        $rules['cache_seconds'] = ['required', 'integer', 'between:0,300'];
        $rules['driver'] = ['required', 'in:pgbackrest,report'];
        $rules['logical_enabled'] = $rules['pitr_required'] = $rules['enforce_production_rules'] = ['required', 'boolean'];
        $rules['repository_names.1'] = $rules['repository_names.2'] = ['required', 'string', 'max:60'];
        $rules['pgbackrest.timeout_seconds'] = ['required', 'integer', 'between:1,120'];
        $rules['pgbackrest.check_timeout_seconds'] = ['required', 'integer', 'between:1,900'];

        return array_keys(Validator::make(config('backup'), $rules)->errors()->toArray());
    }

    /**
     * Probe results only (tool, WAL, published evidence) are cached briefly so page renders do not
     * start processes; thresholds and history are re-evaluated on every call.
     */
    private function probes(bool $fresh): array
    {
        $ttl = (int) config('backup.cache_seconds');
        $key = self::CACHE_KEY.':'.$this->adapter->driver();
        $probe = function (): array {
            $this->probedNow = true;

            return ['infrastructure' => $this->adapter->inspect()->toArray(), 'wal' => $this->wal->inspect(), 'evidence' => $this->evidence->read()];
        };
        if ($fresh || $ttl === 0) {
            $probes = $probe();
            if ($ttl > 0) {
                Cache::put($key, $probes, $ttl);
            }

            return $probes;
        }

        return Cache::remember($key, $ttl, $probe);
    }

    /** @return list<array>|null null when the history table is unavailable (migration not applied) */
    private function history(): ?array
    {
        try {
            return BackupOperation::query()->latest('started_at')->limit(500)->get()
                ->map(fn (BackupOperation $operation) => ['id' => $operation->id, 'type' => $operation->type, 'status' => $operation->status,
                    'repository' => $operation->repository, 'started_at' => $operation->started_at->getTimestamp(),
                    'completed_at' => $operation->completed_at?->getTimestamp()])->all();
        } catch (QueryException) {
            return null;
        }
    }

    /** Structured failure record: codes and exit status only, never output, paths or environment values. */
    private function log(BackupHealthStatus $status): void
    {
        if (in_array($status->overallStatus, ['HEALTHY', 'WARNING', 'NOT_CONFIGURED'], true)) {
            return;
        }
        Log::log($status->overallStatus === 'CRITICAL' ? 'error' : 'warning', 'backup.health.failed', [
            'overall_status' => $status->overallStatus,
            'reason_code' => $status->reasonCode,
            'component' => $status->infrastructure['driver'],
            'exit_code' => $status->infrastructure['diagnostics']['exit_code'] ?? null,
            'environment' => $status->environment,
            'checked_at' => $status->checkedAt,
        ]);
    }
}
