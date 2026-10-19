<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection;

use App\Models\IntelligenceDeployment;
use App\Models\IntelligenceErrorFingerprint;
use App\Models\IntelligenceIncident;
use App\Models\IntelligenceIncidentEvent;
use App\Models\IntelligenceRecommendation;
use App\Models\User;
use App\Services\Intelligence\System\IncidentNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns this run's findings into incidents without storms (brief §27–29,
 * §100–101): the same fingerprint stays one incident (occurrences up,
 * evidence refreshed, severity only ever escalated by a rule), a finding
 * that stops firing for CLEAR_RUNS runs mitigates its incident, a mitigated
 * incident with no recurrence for RESOLVE_AFTER_HOURS resolves — and every
 * change is an immutable event. People close incidents; the engine never
 * does.
 */
final class IncidentManager
{
    public const CLEAR_RUNS = 3;

    public const RESOLVE_AFTER_HOURS = 24;

    /** A deployment this close before detection is linked as a correlation, never a cause. */
    public const DEPLOYMENT_WINDOW_MINUTES = 60;

    public function __construct(private readonly IncidentNotifier $notifier) {}

    /**
     * @param  list<Finding>  $findings
     * @param  list<string>  $evaluatedRules  Rules that ran this time; only their incidents may clear.
     * @return array{opened: int, recurred: int, updated: int, mitigated: int, resolved: int}
     */
    public function apply(array $findings, array $evaluatedRules, CarbonImmutable $now): array
    {
        $summary = ['opened' => 0, 'recurred' => 0, 'updated' => 0, 'mitigated' => 0, 'resolved' => 0];
        $seen = [];

        foreach ($findings as $finding) {
            $fingerprint = $finding->fingerprint();
            $seen[$fingerprint] = true;
            $incident = IntelligenceIncident::query()->where('fingerprint', $fingerprint)->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)->first();

            if ($incident === null) {
                $this->open($finding, $now);
                $summary['opened']++;

                continue;
            }

            $this->refresh($incident, $finding, $now);
            $summary[$incident->status === 'mitigated' ? 'recurred' : 'updated']++;
            if ($incident->status === 'mitigated') {
                $this->transition($incident, 'open', null, 'recurred', $now);
            }
        }

        // Conditions that stopped: count clear runs, then mitigate; mitigated and quiet long enough: resolve.
        $candidates = IntelligenceIncident::query()->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)->whereIn('rule', $evaluatedRules)->get();
        foreach ($candidates as $incident) {
            if (isset($seen[$incident->fingerprint])) {
                continue;
            }
            if (in_array($incident->status, ['open', 'investigating'], true)) {
                $incident->clear_runs = (int) $incident->clear_runs + 1;
                if ($incident->clear_runs >= self::CLEAR_RUNS) {
                    $incident->mitigated_at = $now;
                    $incident->save();
                    $this->transition($incident, 'mitigated', null, 'mitigated', $now, 'auto:condition_not_observed');
                    $summary['mitigated']++;
                } else {
                    $incident->save();
                }
            } elseif ($incident->status === 'mitigated' && $incident->mitigated_at !== null && $incident->mitigated_at->lte($now->subHours(self::RESOLVE_AFTER_HOURS))) {
                $incident->resolved_at = $now;
                $incident->verification_status = 'pending';
                $incident->save();
                $this->transition($incident, 'resolved', null, 'resolved', $now, 'auto:no_recurrence');
                $summary['resolved']++;
            }
        }

        return $summary;
    }

    /** A person changes status, owner or resolution; audited as an event. */
    public function transition(IntelligenceIncident $incident, string $to, ?User $actor, string $event = 'status_changed', ?CarbonImmutable $at = null, ?string $note = null): void
    {
        $from = $incident->status;
        $at ??= CarbonImmutable::now();
        $incident->status = $to;
        if ($to === 'mitigated' && $incident->mitigated_at === null) {
            $incident->mitigated_at = $at;
        }
        if ($to === 'resolved' && $incident->resolved_at === null) {
            $incident->resolved_at = $at;
        }
        if ($to === 'closed') {
            $incident->closed_at = $at;
        }
        if (in_array($to, ['open', 'investigating'], true)) {
            $incident->clear_runs = 0;
        }
        $incident->save();

        $this->event($incident, $event, $from, $to, $actor, $at, $note);
    }

    public function event(IntelligenceIncident $incident, string $event, ?string $from, ?string $to, ?User $actor, ?CarbonImmutable $at = null, ?string $note = null, ?array $payload = null): IntelligenceIncidentEvent
    {
        return IntelligenceIncidentEvent::query()->create([
            'incident_id' => $incident->id,
            'event' => $event,
            'from_value' => $from,
            'to_value' => $to,
            'note' => $note,
            'payload' => $payload,
            'actor_user_id' => $actor?->id,
            'occurred_at' => $at ?? CarbonImmutable::now(),
        ]);
    }

    private function open(Finding $finding, CarbonImmutable $now): void
    {
        $deployment = IntelligenceDeployment::query()
            ->where('environment', (string) app()->environment())
            ->whereBetween('deployed_at', [$now->subMinutes(self::DEPLOYMENT_WINDOW_MINUTES)->toDateTimeString(), $now->toDateTimeString()])
            ->orderByDesc('deployed_at')
            ->first();

        $incident = DB::transaction(function () use ($finding, $now, $deployment): IntelligenceIncident {
            $incident = IntelligenceIncident::query()->create([
                'reference' => $this->reference($now),
                'title' => "intelligence.rules.{$finding->rule}.title",
                'severity' => $finding->severity,
                'status' => 'open',
                'component' => $finding->component,
                'module' => $finding->module,
                'endpoint' => $finding->endpoint ?? $finding->dimension,
                'fingerprint' => $finding->fingerprint(),
                'rule' => $finding->rule,
                'detected_at' => $now,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'occurrences' => 1,
                'impact_summary' => "intelligence.rules.{$finding->rule}.impact",
                'evidence' => $finding->evidencePayload(),
                'source_signals' => $finding->signals,
                'deployment_id' => $deployment?->id,
                'clear_runs' => 0,
            ]);

            $this->event($incident, 'detected', null, 'open', null, $now, null, ['severity' => $finding->severity]);
            if ($finding->rule === 'error_repeat' && $finding->dimension !== null) {
                IntelligenceErrorFingerprint::query()->where('fingerprint', $finding->dimension)->update(['incident_id' => $incident->id]);
            }
            foreach (RecommendationTemplates::for($finding->rule) as $template) {
                IntelligenceRecommendation::query()->create([
                    'source_type' => 'incident',
                    'source_id' => $incident->reference,
                    'incident_id' => $incident->id,
                    'category' => $template['category'],
                    'risk' => $template['risk'],
                    'title' => $template['title'],
                    'body' => $template['body'],
                    'evidence' => $finding->evidencePayload(),
                    'generated_by' => 'rule',
                    'generated_at' => $now,
                ]);
            }
            if (RecommendationTemplates::for($finding->rule) !== []) {
                $this->event($incident, 'recommendation_created', null, null, null, $now, null, ['count' => count(RecommendationTemplates::for($finding->rule))]);
            }

            return $incident;
        });

        try {
            $this->notifier->opened($incident);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function refresh(IntelligenceIncident $incident, Finding $finding, CarbonImmutable $now): void
    {
        $incident->last_seen_at = $now;
        $incident->occurrences = (int) $incident->occurrences + 1;
        $incident->evidence = $finding->evidencePayload();
        $incident->source_signals = $finding->signals;
        $incident->clear_runs = 0;

        if ($finding->weight() > $this->weight($incident->severity)) {
            $from = $incident->severity;
            $incident->severity = $finding->severity;
            $incident->save();
            $this->event($incident, 'escalated', $from, $finding->severity, null, $now);
            try {
                $this->notifier->escalated($incident);
            } catch (Throwable $e) {
                report($e);
            }

            return;
        }

        $incident->save();
    }

    private function weight(string $severity): int
    {
        return (int) array_search($severity, array_reverse(IntelligenceIncident::SEVERITIES), true);
    }

    /** INC-YYMM-NNNN; retried once on a reference collision. */
    private function reference(CarbonImmutable $now): string
    {
        $prefix = 'INC-'.$now->format('ym').'-';
        $count = IntelligenceIncident::query()->where('reference', 'like', $prefix.'%')->count();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $prefix.str_pad((string) ($count + 1 + $attempt), 4, '0', STR_PAD_LEFT);
            if (! IntelligenceIncident::query()->where('reference', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new QueryException('', 'incident reference', [], new \RuntimeException('Could not allocate an incident reference'));
    }
}
