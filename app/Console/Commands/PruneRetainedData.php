<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Grievance;
use App\Services\Audit\AuditLogSealer;
use App\Services\SystemSettings\SystemSettingsRegistry;
use App\Services\SystemSettings\SystemSettingsService;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes log rows past their retention window (config/retention.php; audit
 * logs and security events follow Security → Audit Retention Days). Deletes
 * in key-ordered batches so a backlog never locks a table or exhausts memory.
 * Financial records, employee history, documents and case records are never
 * touched (risk register DB-9 decision).
 */
class PruneRetainedData extends Command
{
    protected $signature = 'data:prune-retention {--dry-run : Count what would be deleted without deleting}';

    protected $description = 'Delete audit, security, notification, verification and reminder rows past their retention window';

    public function handle(SystemSettingsService $settings, AuditLogSealer $sealer): int
    {
        $auditDays = max(30, (int) $settings->get(SystemSettingsRegistry::GROUP_SECURITY, 'audit_retention_days', 730));
        $readDays = max(1, (int) config('retention.notifications_read_days', 90));
        $notificationDays = max($readDays, (int) config('retention.notifications_days', 365));
        $verificationDays = max(1, (int) config('retention.verification_logs_days', 365));
        $reminderDays = max(1, (int) config('retention.daily_activity_reminders_days', 90));

        $targets = [
            // Audit rows about a grievance stay until the case itself is
            // archived and off legal hold: its own retention governs them.
            ['audit_logs', 'id', 'created_at', $auditDays, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereNull('auditable_type')
                ->orWhere('auditable_type', '!=', Grievance::class)
                ->orWhereNotIn('auditable_id', DB::table('grievances')
                    // auditable_id is a string column (it holds integer and uuid keys).
                    ->whereNull('archived_at')->orWhere('legal_hold', true)->selectRaw('CAST(id AS VARCHAR(64))')))],
            ['security_events', 'id', 'created_at', $auditDays, null],
            ['notifications', 'id', 'read_at', $readDays, null],
            ['notifications', 'id', 'created_at', $notificationDays, null],
            ['card_verifications', 'id', 'created_at', $verificationDays, null],
            ['nfc_verification_logs', 'id', 'occurred_at', $verificationDays, null],
            ['daily_activity_reminders', 'id', 'activity_date', $reminderDays, null],
        ];

        $dryRun = (bool) $this->option('dry-run');

        foreach ($targets as [$table, $key, $column, $days, $constraint]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $count = $this->prune($table, $key, $column, now()->subDays($days), $constraint, $dryRun);
            $this->line(sprintf('%s.%s older than %d days: %d %s', $table, $column, $days, $count, $dryRun ? 'would be deleted' : 'deleted'));
        }

        // The seals of pruned audit days go with them (the cutoff day is partly pruned).
        if (! $dryRun && $sealer->available()) {
            $this->line('audit_log_seals through the audit cutoff: '.$sealer->forgetThrough(now()->subDays($auditDays)->toDateString()).' deleted');
        }

        return self::SUCCESS;
    }

    /** @param (Closure(Builder): mixed)|null $constraint */
    private function prune(string $table, string $key, string $column, Carbon $cutoff, ?Closure $constraint, bool $dryRun): int
    {
        $expired = fn (): Builder => DB::table($table)
            ->whereNotNull($column)
            ->where($column, '<', $cutoff)
            ->when($constraint, $constraint);

        if ($dryRun) {
            return $expired()->count();
        }

        $batch = max(1, (int) config('retention.batch_size', 1000));
        $deleted = 0;

        do {
            $ids = $expired()->orderBy($key)->limit($batch)->pluck($key);
            $removed = $ids->isEmpty() ? 0 : DB::table($table)->whereIn($key, $ids->all())->delete();
            $deleted += $removed;
        } while ($removed > 0);

        return $deleted;
    }
}
