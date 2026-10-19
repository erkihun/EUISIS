<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Intelligence;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\IntelligenceErrorFingerprint;
use App\Models\IntelligenceIncident;
use App\Models\IntelligenceIncidentEvent;
use App\Models\IntelligenceRecommendation;
use App\Models\User;
use App\Services\Intelligence\IntelligenceLabels;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\System\Detection\IncidentManager;
use App\Services\Intelligence\System\RunbookCatalog;
use App\Services\Intelligence\System\Telemetry\Metrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Incidents (docs/intelligence-system-health.md): the list, the detail page
 * (summary, timeline, evidence, related errors, deployment, recommendations,
 * runbook) and the human transitions — status, ownership, resolution and
 * verification — each recorded as an event and audited. Fingerprint sample
 * context (file, line, frames) is shown only with the diagnostics grant.
 */
final class IncidentController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly IncidentManager $incidents,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...IntelligenceIncident::STATUSES, 'active'])],
            'severity' => ['nullable', Rule::in(IntelligenceIncident::SEVERITIES)],
            'component' => ['nullable', Rule::in(Metrics::components())],
        ]);
        $status = $filters['status'] ?? 'active';

        $query = IntelligenceIncident::query()->with('owner:id,name')->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 when 'low' then 3 else 4 end")->orderByDesc('last_seen_at');
        $status === 'active' ? $query->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES) : $query->where('status', $status);
        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
        if (! empty($filters['component'])) {
            $query->where('component', $filters['component']);
        }

        $counts = IntelligenceIncident::query()->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)->selectRaw('severity, count(*) as c')->groupBy('severity')->pluck('c', 'severity');

        return Inertia::render('Intelligence/Incidents/Index', [
            'labels' => IntelligenceLabels::dictionary(),
            'filters' => ['status' => $status, 'severity' => $filters['severity'] ?? '', 'component' => $filters['component'] ?? ''],
            'options' => ['statuses' => IntelligenceIncident::STATUSES, 'severities' => IntelligenceIncident::SEVERITIES, 'components' => Metrics::components()],
            'counts' => ['critical' => (int) ($counts['critical'] ?? 0), 'high' => (int) ($counts['high'] ?? 0), 'medium' => (int) ($counts['medium'] ?? 0), 'low' => (int) ($counts['low'] ?? 0), 'info' => (int) ($counts['info'] ?? 0)],
            'incidents' => $query->paginate(self::PER_PAGE)->withQueryString()->through(fn (IntelligenceIncident $i): array => $this->summary($i)),
            'can' => $this->can($request->user()),
        ]);
    }

    public function show(Request $request, IntelligenceIncident $incident): Response
    {
        /** @var User $user */
        $user = $request->user();
        $can = $this->can($user);
        $incident->load(['owner:id,name', 'deployment', 'events.actor:id,name', 'recommendations.reviewer:id,name', 'fingerprints']);

        return Inertia::render('Intelligence/Incidents/Show', [
            'labels' => IntelligenceLabels::dictionary(),
            'incident' => [
                ...$this->summary($incident),
                'rule' => $incident->rule,
                'module' => $incident->module,
                'first_seen_at' => $incident->first_seen_at->toIso8601String(),
                'mitigated_at' => $incident->mitigated_at?->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
                'closed_at' => $incident->closed_at?->toIso8601String(),
                'impact' => $incident->impact_summary,
                'evidence' => $incident->evidence,
                'source_signals' => $incident->source_signals ?? [],
                'probable_cause' => $incident->probable_cause,
                'cause_confidence' => $incident->cause_confidence,
                'verification_status' => $incident->verification_status,
                'resolution_summary' => $incident->resolution_summary,
                'deployment' => $incident->deployment === null ? null : [
                    'release' => $incident->deployment->release,
                    'commit_hash' => $incident->deployment->commit_hash,
                    'deployed_at' => $incident->deployment->deployed_at->toIso8601String(),
                    'minutes_before' => (int) $incident->deployment->deployed_at->diffInMinutes($incident->detected_at),
                ],
            ],
            'events' => $incident->events->map(fn (IntelligenceIncidentEvent $e): array => [
                'id' => $e->id, 'event' => $e->event, 'from' => $e->from_value, 'to' => $e->to_value, 'note' => $e->note, 'payload' => $e->payload,
                'actor' => $e->actor?->name, 'occurred_at' => $e->occurred_at->toIso8601String(),
            ])->all(),
            'recommendations' => $incident->recommendations->map(fn (IntelligenceRecommendation $r): array => $this->recommendation($r))->all(),
            'fingerprints' => $incident->fingerprints->map(fn (IntelligenceErrorFingerprint $f): array => [
                'exception_class' => $f->exception_class, 'message' => $f->message_normalized, 'route_name' => $f->route_name, 'route_group' => $f->route_group,
                'occurrences' => $f->occurrences, 'first_seen_at' => $f->first_seen_at->toIso8601String(), 'last_seen_at' => $f->last_seen_at->toIso8601String(),
                'last_error_id' => $can['diagnostics'] ? $f->last_error_id : null,
                'sample_context' => $can['diagnostics'] ? $f->sample_context : null,
            ])->all(),
            'runbook' => RunbookCatalog::forRule($incident->rule),
            'options' => ['statuses' => IntelligenceIncident::STATUSES, 'verifications' => IntelligenceIncident::VERIFICATIONS],
            'can' => $can,
        ]);
    }

    public function update(Request $request, IntelligenceIncident $incident): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'status' => ['nullable', Rule::in(IntelligenceIncident::STATUSES)],
            'owner' => ['nullable', Rule::in(['me', 'none'])],
            'note' => ['nullable', 'string', 'max:2000'],
            'resolution_summary' => ['nullable', 'string', 'max:4000'],
            'verification_status' => ['nullable', Rule::in(IntelligenceIncident::VERIFICATIONS)],
        ]);
        $before = $incident->only(['status', 'owner_user_id', 'verification_status']);

        if (! empty($data['owner'])) {
            $from = $incident->owner_user_id;
            $incident->owner_user_id = $data['owner'] === 'me' ? $user->id : null;
            $incident->save();
            $this->incidents->event($incident, 'owner_changed', $from !== null ? (string) $from : null, $incident->owner_user_id !== null ? (string) $incident->owner_user_id : null, $user);
        }
        if (isset($data['resolution_summary'])) {
            $incident->resolution_summary = $data['resolution_summary'];
            $incident->save();
        }
        if (! empty($data['verification_status'])) {
            $incident->verification_status = $data['verification_status'];
            $incident->save();
            $this->incidents->event($incident, 'verified', null, $data['verification_status'], $user, note: $data['note'] ?? null);
        }
        if (! empty($data['status']) && $data['status'] !== $incident->status) {
            $event = match ($data['status']) {
                'mitigated' => 'mitigated', 'resolved' => 'resolved', 'closed' => 'closed', default => 'status_changed',
            };
            $this->incidents->transition($incident, $data['status'], $user, $event, note: $data['note'] ?? null);
        } elseif (! empty($data['note']) && empty($data['verification_status'])) {
            $this->incidents->event($incident, 'note', null, null, $user, note: $data['note']);
        }

        $this->audit->execute(
            AuditEventType::IntelligenceIncidentUpdated,
            $user,
            $incident,
            oldValues: $before,
            newValues: $incident->only(['status', 'owner_user_id', 'verification_status']),
            request: $request,
        );

        return back()->with('flash', ['message' => __('intelligence.flash.incident_updated'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function summary(IntelligenceIncident $i): array
    {
        return [
            'id' => $i->id, 'reference' => $i->reference, 'title' => $i->title, 'params' => $i->evidence['params'] ?? [], 'severity' => $i->severity,
            'status' => $i->status, 'component' => $i->component, 'endpoint' => $i->endpoint, 'occurrences' => $i->occurrences,
            'detected_at' => $i->detected_at->toIso8601String(), 'last_seen_at' => $i->last_seen_at->toIso8601String(),
            'owner' => $i->owner?->name, 'href' => route('intelligence.incidents.show', $i->id),
        ];
    }

    /** @return array<string, mixed> */
    private function recommendation(IntelligenceRecommendation $r): array
    {
        return [
            'id' => $r->id, 'category' => $r->category, 'risk' => $r->risk, 'title' => $r->title, 'body' => $r->body, 'generated_by' => $r->generated_by,
            'confidence' => $r->confidence, 'review_status' => $r->review_status, 'reviewed_at' => $r->reviewed_at?->toIso8601String(), 'reviewer' => $r->reviewer?->name,
            'review_note' => $r->review_note, 'params' => $r->evidence['params'] ?? [],
        ];
    }

    /** @return array{manage: bool, review: bool, diagnostics: bool, runbooks: bool} */
    private function can(User $user): array
    {
        return [
            'manage' => $user->can(IntelligencePermissions::INCIDENTS_MANAGE),
            'review' => $user->can(IntelligencePermissions::RECOMMENDATIONS_REVIEW),
            'diagnostics' => $user->can(IntelligencePermissions::DIAGNOSTICS),
            'runbooks' => $user->can(IntelligencePermissions::RUNBOOKS),
        ];
    }
}
