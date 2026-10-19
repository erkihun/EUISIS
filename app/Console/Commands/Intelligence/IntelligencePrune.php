<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use App\Models\IntelligenceErrorFingerprint;
use App\Models\IntelligenceIncident;
use App\Models\IntelligenceRecommendation;
use App\Services\Intelligence\IntelligenceSettings;
use App\Services\Intelligence\System\Telemetry\SignalRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Retention (brief §120): telemetry samples, quiet error fingerprints,
 * CLOSED incidents (with their events) and reviewed or stale recommendations
 * older than System Settings › Intelligence › Retention are deleted. Active
 * incidents and pending reviews are never pruned.
 */
final class IntelligencePrune extends Command
{
    protected $signature = 'intelligence:prune {--days= : Override the configured retention}';

    protected $description = 'Delete Intelligence telemetry, closed incidents and old recommendations past retention';

    public function handle(SignalRepository $signals, IntelligenceSettings $settings): int
    {
        $days = (int) ($this->option('days') ?: $settings->retentionDays());
        $before = CarbonImmutable::now()->subDays(max(1, $days));

        $removedSignals = $signals->prune($before);
        $removedFingerprints = IntelligenceErrorFingerprint::query()->where('last_seen_at', '<', $before->toDateTimeString())->whereNull('incident_id')->delete();
        $removedIncidents = IntelligenceIncident::query()->where('status', 'closed')->where('closed_at', '<', $before->toDateTimeString())->delete();
        $removedRecommendations = IntelligenceRecommendation::query()
            ->where('generated_at', '<', $before->toDateTimeString())
            ->where(fn ($q) => $q->where('review_status', '!=', 'pending')->orWhereNull('incident_id'))
            ->whereDoesntHave('incident', fn ($q) => $q->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES))
            ->delete();

        $this->info("Pruned before {$before->toDateString()}: signals {$removedSignals}, fingerprints {$removedFingerprints}, incidents {$removedIncidents}, recommendations {$removedRecommendations}.");

        return self::SUCCESS;
    }
}
