<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebhookDelivery;
use App\Services\CoreSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention for integration data (ADR 0010), from Core settings › Integration:
 * an event is removed only when it is older than the retention and every
 * delivery of it was delivered (one still pending or failed stays, whatever
 * its age; one no webhook subscribed to goes with its age); API call logs older than theirs are removed.
 */
class PruneIntegrationData extends Command
{
    protected $signature = 'core:prune-integration-data {--dry-run : Count only}';

    protected $description = 'Remove delivered integration events and API call logs past their retention';

    public function handle(CoreSettings $settings): int
    {
        $eventsBefore = now()->subDays($settings->int('integration.outbox_retention_days'));
        $logsBefore = now()->subDays($settings->int('integration.api_log_retention_days'));
        $events = DB::table('outbox_events')->where('occurred_at', '<', $eventsBefore)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('webhook_deliveries')->whereColumn('webhook_deliveries.outbox_event_id', 'outbox_events.id')
                ->where('webhook_deliveries.status', '!=', WebhookDelivery::DELIVERED));
        $logs = DB::table('api_request_logs')->where('created_at', '<', $logsBefore);

        if ($this->option('dry-run')) {
            $this->info($events->count().' event(s) and '.$logs->count().' API log row(s) would be removed.');

            return self::SUCCESS;
        }
        $removedEvents = 0;
        $events->orderBy('id')->select('id')->chunkById(500, function ($chunk) use (&$removedEvents): void {
            $ids = $chunk->pluck('id')->all();
            DB::transaction(function () use ($ids, &$removedEvents): void {
                DB::table('webhook_deliveries')->whereIn('outbox_event_id', $ids)->where('status', WebhookDelivery::DELIVERED)->delete();
                $removedEvents += DB::table('outbox_events')->whereIn('id', $ids)
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('webhook_deliveries')->whereColumn('webhook_deliveries.outbox_event_id', 'outbox_events.id'))->delete();
            });
        });
        $removedLogs = 0;
        do {
            $batch = DB::table('api_request_logs')->where('created_at', '<', $logsBefore)->orderBy('id')->limit(1000)->pluck('id');
            $removedLogs += $batch->isEmpty() ? 0 : DB::table('api_request_logs')->whereIn('id', $batch)->delete();
        } while ($batch->count() === 1000);
        $this->info("{$removedEvents} event(s) and {$removedLogs} API log row(s) removed.");

        return self::SUCCESS;
    }
}
