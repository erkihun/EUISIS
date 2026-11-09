<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Alerts;

use App\Models\IntelligenceAlert;
use App\Models\IntelligenceIncident;
use App\Services\Intelligence\IntelligenceArea;
use App\Services\Intelligence\IntelligenceService;
use App\Services\Intelligence\SystemContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The alert engine (brief §65–66): one deduplicated alert per source
 * condition, from active incidents (technical) and from the city-wide
 * business insights at medium severity or above (business); repeated
 * detections raise the occurrence count, a condition absent for CLEAR_RUNS
 * runs resolves its alert, a resolved condition re-fires only after the
 * cooldown. Acknowledging and resolving by hand is the controller's job.
 */
final class AlertEngine
{
    public const CLEAR_RUNS = 3;

    public const COOLDOWN_MINUTES = 60;

    public const BUSINESS_AREAS = [IntelligenceArea::Workforce, IntelligenceArea::PublicVoice, IntelligenceArea::Performance, IntelligenceArea::DataQuality];

    private const RANK = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    public function __construct(
        private readonly IntelligenceService $intelligence,
        private readonly SystemContext $system,
        private readonly AlertNotifier $notifier,
    ) {}

    /** @return array{opened: int, updated: int, resolved: int, skipped_business: bool, failed_areas: list<string>} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $seen = [];
        $summary = ['opened' => 0, 'updated' => 0, 'resolved' => 0, 'skipped_business' => false, 'failed_areas' => []];

        foreach ($this->technical() as $candidate) {
            $seen[$candidate['source_key']] = true;
            $this->upsert($candidate, $now, $summary);
        }

        $context = $this->system->cityWide();
        if ($context === null) {
            $summary['skipped_business'] = true;
        } else {
            foreach (self::BUSINESS_AREAS as $area) {
                try {
                    foreach ($this->business($area, $context) as $candidate) {
                        $seen[$candidate['source_key']] = true;
                        $this->upsert($candidate, $now, $summary);
                    }
                } catch (Throwable $e) {
                    report($e);
                    $summary['failed_areas'][] = $area->value;
                }
            }
        }

        // Conditions that stopped: count clear runs, then resolve with a cooldown.
        foreach (IntelligenceAlert::query()->whereIn('status', IntelligenceAlert::ACTIVE_STATUSES)->get() as $alert) {
            if (isset($seen[$alert->source_key])) {
                continue;
            }
            if ($alert->domain === 'business' && ($context === null || in_array($alert->area, $summary['failed_areas'], true))) {
                continue; // not evaluated this run: leave it alone
            }
            $alert->clear_runs = (int) $alert->clear_runs + 1;
            if ($alert->clear_runs >= self::CLEAR_RUNS) {
                $alert->status = 'resolved';
                $alert->resolved_at = $now;
                $alert->cooldown_until = $now->addMinutes(self::COOLDOWN_MINUTES);
                $summary['resolved']++;
            }
            $alert->save();
        }

        return $summary;
    }

    /** @return list<array<string, mixed>> */
    private function technical(): array
    {
        return IntelligenceIncident::query()->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)->get()
            ->map(fn (IntelligenceIncident $i): array => [
                'type' => 'incident', 'domain' => 'technical', 'source_key' => 'incident:'.$i->fingerprint, 'severity' => $i->severity,
                'title' => $i->title, 'params' => $i->evidence['params'] ?? [], 'evidence' => $i->evidence['rows'] ?? [],
                'recommendation' => null, 'href' => route('intelligence.incidents.show', $i->id), 'organization_id' => null, 'area' => 'incidents',
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function business(IntelligenceArea $area, $context): array
    {
        $payload = $this->intelligence->area($area, $context)['payload'];
        $out = [];
        foreach ($payload['insights'] as $insight) {
            if ((self::RANK[$insight['severity']] ?? 0) < self::RANK['medium']) {
                continue;
            }
            $out[] = [
                'type' => 'insight', 'domain' => 'business', 'source_key' => 'insight:'.$area->value.':'.$insight['key'], 'severity' => $insight['severity'],
                'title' => $insight['title'], 'params' => (array) $insight['title_params'], 'evidence' => $insight['evidence'],
                'recommendation' => $insight['recommendation'], 'href' => $insight['href'] ?? route('intelligence.area', $area->value), 'organization_id' => null, 'area' => $area->value,
            ];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $candidate  @param  array<string, int|bool|list<string>>  $summary */
    private function upsert(array $candidate, CarbonImmutable $now, array &$summary): void
    {
        $open = IntelligenceAlert::query()->where('source_key', $candidate['source_key'])->whereIn('status', IntelligenceAlert::ACTIVE_STATUSES)->first();
        if ($open !== null) {
            $open->occurrences = (int) $open->occurrences + 1;
            $open->last_detected_at = $now;
            $open->clear_runs = 0;
            $open->params = $candidate['params'];
            $open->evidence = $candidate['evidence'];
            if ((self::RANK[$candidate['severity']] ?? 0) > (self::RANK[$open->severity] ?? 0)) {
                $open->severity = $candidate['severity'];
            }
            $open->save();
            $summary['updated']++;

            return;
        }

        $cooling = IntelligenceAlert::query()->where('source_key', $candidate['source_key'])->where('status', 'resolved')->where('cooldown_until', '>', $now->toDateTimeString())->exists();
        if ($cooling) {
            return;
        }

        $alert = IntelligenceAlert::query()->create([...$candidate, 'status' => 'open', 'occurrences' => 1, 'clear_runs' => 0, 'first_detected_at' => $now, 'last_detected_at' => $now]);
        $summary['opened']++;
        if ($alert->domain === 'business') {
            try {
                $this->notifier->opened($alert);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
