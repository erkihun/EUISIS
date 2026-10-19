<?php

declare(strict_types=1);

namespace App\Services\Intelligence\AI;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\IntelligenceErrorFingerprint;
use App\Models\IntelligenceIncident;
use App\Models\IntelligenceRecommendation;
use App\Models\User;
use App\Services\Intelligence\AI\Contracts\LLMGateway;
use App\Services\Intelligence\System\ComponentHealth;
use App\Services\Intelligence\System\Detection\IncidentManager;
use Illuminate\Support\Str;

/**
 * The root-cause assistant (brief §101–103, §116): builds a compact, redacted
 * evidence bundle for one incident — the rule, its measured evidence, related
 * error signatures (class, normalized message, route group, file and line of
 * the first application frame; never source code or stack contents), the
 * deployment within the window and the component states — and asks the
 * gateway for an assessment. The answer is advisory: it is stored on the
 * incident with its confidence, its next checks become AI-generated
 * recommendations awaiting review, and nothing is executed.
 */
final class RootCauseAssistant
{
    public const MAX_FINGERPRINTS = 5;

    public function __construct(
        private readonly LLMGateway $gateway,
        private readonly ComponentHealth $health,
        private readonly IncidentManager $incidents,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /** @return array{ok: bool, reason: ?string} */
    public function explain(IntelligenceIncident $incident, ?User $actor, string $locale = 'en'): array
    {
        $incident->loadMissing(['deployment', 'fingerprints']);
        $bundle = $this->bundle($incident);

        $result = $this->gateway->complete(new AiRequest(
            feature: 'root_cause_assistant',
            promptKey: 'incident_root_cause',
            input: json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
            dataClasses: ['structured_signals', 'redacted_logs'],
            variables: [],
            triggeredByUserId: $actor?->id,
            subjectType: 'intelligence_incident',
            subjectId: $incident->id,
            locale: $locale,
        ));

        if (! $result->ok || $result->json === null) {
            $this->incidents->event($incident, 'ai_explain_failed', null, $result->reason, $actor, note: $result->error !== null ? Str::limit($result->error, 300) : null, payload: ['run_id' => $result->runId]);

            return ['ok' => false, 'reason' => $result->reason];
        }

        $assessment = $result->json;
        $top = $assessment['likely_causes'][0] ?? null;
        $incident->forceFill([
            'probable_cause' => trim(($assessment['observed_symptom'] ?? '').($top !== null ? ' — '.$top['cause'] : '')),
            'cause_confidence' => $assessment['overall_confidence'] ?? null,
            'recommendation' => ['ai_assessment' => $assessment, 'prompt_version' => $result->promptVersion, 'model' => $result->model, 'generated_at' => now()->toIso8601String()],
            'ai_run_id' => $result->runId,
        ])->save();

        $now = now();
        foreach (array_slice($assessment['recommended_next_checks'] ?? [], 0, 6) as $check) {
            IntelligenceRecommendation::query()->create([
                'source_type' => 'incident', 'source_id' => $incident->reference, 'incident_id' => $incident->id, 'area' => 'incidents',
                'category' => 'verification', 'risk' => 'safe_check', 'title' => Str::limit($check, 180), 'body' => $check,
                'evidence' => ['from' => 'ai_assessment', 'overall_confidence' => $assessment['overall_confidence'] ?? null],
                'confidence' => $assessment['overall_confidence'] ?? null, 'generated_by' => 'ai', 'prompt_version' => $result->promptVersion,
                'ai_run_id' => $result->runId, 'generated_at' => $now, 'review_status' => 'pending',
            ]);
        }

        $this->incidents->event($incident, 'ai_explained', null, $assessment['overall_confidence'] ?? null, $actor, payload: ['run_id' => $result->runId, 'model' => $result->model, 'prompt_version' => $result->promptVersion]);
        $this->audit->execute(AuditEventType::IntelligenceIncidentExplained, $actor, $incident, newValues: ['run_id' => $result->runId, 'confidence' => $assessment['overall_confidence'] ?? null, 'prompt_version' => $result->promptVersion]);

        return ['ok' => true, 'reason' => null];
    }

    /**
     * The evidence bundle as the model sees it: structured, bounded, redacted
     * once more by the gateway. Exposed so tests and Governance can show what
     * leaves the platform.
     *
     * @return array<string, mixed>
     */
    public function bundle(IntelligenceIncident $incident): array
    {
        $states = $this->health->states();
        $deployment = $incident->deployment;

        return [
            'incident' => [
                'reference' => $incident->reference, 'rule' => $incident->rule, 'component' => $incident->component, 'endpoint' => $incident->endpoint,
                'severity' => $incident->severity, 'status' => $incident->status, 'occurrences' => $incident->occurrences,
                'detected_at' => $incident->detected_at->toIso8601String(), 'last_seen_at' => $incident->last_seen_at->toIso8601String(),
                'evidence' => Redactor::array($incident->evidence ?? []),
            ],
            'related_errors' => $incident->fingerprints->take(self::MAX_FINGERPRINTS)->map(fn (IntelligenceErrorFingerprint $f): array => [
                'exception_class' => $f->exception_class, 'message' => Redactor::text($f->message_normalized), 'route_group' => $f->route_group, 'route_name' => $f->route_name,
                'occurrences' => $f->occurrences,
                'file' => is_array($f->sample_context) ? ($f->sample_context['file'] ?? null) : null, 'line' => is_array($f->sample_context) ? ($f->sample_context['line'] ?? null) : null,
            ])->values()->all(),
            'recent_deployment' => $deployment === null ? null : [
                'release' => $deployment->release, 'deployed_at' => $deployment->deployed_at->toIso8601String(),
                'minutes_before_detection' => (int) $deployment->deployed_at->diffInMinutes($incident->detected_at),
            ],
            'component_states' => array_map(fn (array $s): array => ['state' => $s['state'], 'reason' => $s['reason'], 'open_incidents' => $s['incidents']], $states),
        ];
    }
}
