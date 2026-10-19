<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\IntelligenceIncident;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\AssistantLabels;
use App\Services\Intelligence\System\ComponentHealth;
use App\Services\Intelligence\System\RouteGroups;
use App\Services\Intelligence\System\RunbookCatalog;
use Throwable;

/**
 * `system_health` and `incident_lookup` (A-5): deterministic component states
 * from ComponentHealth (UNKNOWN when nothing was sampled) and active
 * incidents. A page correlation needs an active incident on the page's route
 * group — never the route name alone. Incident references and links appear
 * only with the incidents grant; others get a generic notice.
 */
final class SystemHealthTool
{
    public const KEY = 'system_health';

    public const LOOKUP_KEY = 'incident_lookup';

    private const MAX_INCIDENTS = 3;

    public function __construct(private readonly ComponentHealth $health, private readonly WriteAuditLogAction $audit) {}

    public function status(AssistantContext $context): ToolResult
    {
        $locale = $context->locale;
        if (! $context->can('diagnostics')) {
            return ToolResult::denied(self::KEY, __('assistant.answers.denied_diagnostics', [], $locale));
        }
        try {
            $states = $this->health->states();
        } catch (Throwable $e) {
            report($e);
            $states = [];
        }
        $this->audit->execute(AuditEventType::AssistantToolUsed, $context->user, null, newValues: ['tool' => self::KEY]);

        $summary = $states === [] ? ['healthy' => 0, 'degraded' => 0, 'unhealthy' => 0, 'unknown' => 0] : $this->health->summary($states);
        $overall = match (true) {
            $summary['unhealthy'] > 0 => 'unhealthy',
            $summary['degraded'] > 0 => 'degraded',
            $summary['healthy'] > 0 && $summary['unknown'] === 0 => 'healthy',
            $summary['healthy'] > 0 => 'degraded',
            default => 'unknown',
        };
        $components = [];
        $latest = null;
        foreach ($states as $key => $state) {
            $components[] = ['key' => $key, 'label' => AssistantLabels::text('intelligence.components.'.$key, [], $locale), 'state' => $state['state']];
            if ($state['at'] !== null && ($latest === null || $state['at'] > $latest)) {
                $latest = $state['at'];
            }
        }
        $active = IntelligenceIncident::query()->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 when 'low' then 3 else 4 end")->orderByDesc('last_seen_at')->get();

        $lines = [__('assistant.answers.status_components', $summary, $locale)];
        $blocks = [
            ['type' => 'status', 'state' => $overall, 'components' => $components, 'updated_at' => $latest, 'reason' => $states === [] || $overall === 'unknown' ? __('assistant.answers.status_unknown', [], $locale) : null],
        ];
        if ($states === [] || $overall === 'unknown') {
            $lines[] = __('assistant.answers.status_unknown', [], $locale);
        }
        // The incident count is an observed fact: one labelled line, not repeated in the prose.
        $blocks[] = ['type' => 'labels', 'kind' => 'observed', 'text' => $active->isEmpty() ? __('assistant.answers.status_no_incidents', [], $locale) : __('assistant.answers.status_incidents', ['count' => $active->count()], $locale)];
        $incidentData = [];
        foreach ($active->take(self::MAX_INCIDENTS) as $incident) {
            $blocks[] = $this->incidentBlock($context, $incident);
            $incidentData[] = $this->incidentData($context, $incident);
        }

        return new ToolResult(self::KEY, 'ok', $blocks, ['overall' => $overall, 'summary' => $summary, 'components' => $components, 'active_incidents' => $active->count(), 'incidents' => $incidentData, 'sampled_at' => $latest], $lines, 'structured_signals');
    }

    /** Active incidents on the page's route group, or the one a reference names. */
    public function lookup(AssistantContext $context, ?string $reference = null): ToolResult
    {
        $locale = $context->locale;
        if (! $context->can('diagnostics') && ! $context->can('incidents')) {
            // Non-technical people still learn that a verified issue exists on their page, without any detail.
            $count = $this->forGroup($context->page['route_group'])->count();

            return new ToolResult(self::LOOKUP_KEY, $count > 0 ? 'ok' : 'empty',
                [['type' => 'notice', 'tone' => $count > 0 ? 'warning' : 'info', 'text' => __($count > 0 ? 'assistant.answers.incident_for_page_generic' : 'assistant.answers.incident_none_for_page', [], $locale)]],
                ['page_incident' => $count > 0], [__($count > 0 ? 'assistant.answers.incident_for_page_generic' : 'assistant.answers.incident_none_for_page', [], $locale)]);
        }

        $query = $reference !== null
            ? IntelligenceIncident::query()->where('reference', strtoupper($reference))
            : $this->forGroup($context->page['route_group']);
        $incidents = $query->orderByDesc('last_seen_at')->limit(self::MAX_INCIDENTS)->get();
        $this->audit->execute(AuditEventType::AssistantToolUsed, $context->user, null, newValues: ['tool' => self::LOOKUP_KEY, 'route_group' => $context->page['route_group'], 'reference' => $reference, 'found' => $incidents->count()]);

        if ($incidents->isEmpty()) {
            $text = __('assistant.answers.incident_none_for_page', [], $locale);

            return new ToolResult(self::LOOKUP_KEY, 'empty', [['type' => 'labels', 'kind' => 'observed', 'text' => $text]], ['page_incident' => false], [$text]);
        }
        $blocks = [['type' => 'labels', 'kind' => 'observed', 'text' => __('assistant.answers.incident_for_page', [], $locale)]];
        $data = [];
        foreach ($incidents as $incident) {
            $blocks[] = $this->incidentBlock($context, $incident);
            $data[] = $this->incidentData($context, $incident);
            $runbook = RunbookCatalog::forRule($incident->rule);
            if ($runbook !== null && $context->user->can('intelligence.runbooks.view')) {
                $blocks[] = ['type' => 'actions', 'items' => [['key' => 'runbook', 'label' => __('assistant.answers.runbook', ['title' => AssistantLabels::text('intelligence.runbooks.'.$runbook.'.title', [], $locale)], $locale), 'href' => '/intelligence/runbooks/'.$runbook]]];
            }
        }

        return new ToolResult(self::LOOKUP_KEY, 'ok', $blocks, ['page_incident' => true, 'incidents' => $data], array_map(fn (array $d): string => $d['title'].' ('.$d['severity'].', '.$d['status'].')', $data), 'structured_signals');
    }

    private function forGroup(string $group)
    {
        return IntelligenceIncident::query()->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)
            ->where(fn ($q) => $q->where('endpoint', $group)->orWhere('module', $group)->orWhere('component', 'application')->orWhere('component', 'database'))
            ->when($group === RouteGroups::OTHER, fn ($q) => $q->whereRaw('1 = 0'));
    }

    /** @return array<string, mixed> */
    private function incidentBlock(AssistantContext $context, IntelligenceIncident $incident): array
    {
        $locale = $context->locale;
        $params = $incident->evidence['params'] ?? [];
        $withDetail = $context->can('incidents');

        return [
            'type' => 'incident',
            'reference' => $withDetail ? $incident->reference : null,
            'title' => AssistantLabels::text($incident->title, $params, $locale),
            'severity' => $incident->severity,
            'status' => $incident->status,
            'impact' => $incident->impact_summary !== null ? AssistantLabels::text($incident->impact_summary, $params, $locale) : null,
            'component' => AssistantLabels::text('intelligence.components.'.$incident->component, [], $locale),
            'updated_at' => $incident->last_seen_at->toIso8601String(),
            'href' => $withDetail ? '/intelligence/incidents/'.$incident->id : null,
        ];
    }

    /** @return array<string, mixed> */
    private function incidentData(AssistantContext $context, IntelligenceIncident $incident): array
    {
        $block = $this->incidentBlock($context, $incident);

        return ['reference' => $block['reference'], 'title' => $block['title'], 'severity' => $block['severity'], 'status' => $block['status'], 'impact' => $block['impact'], 'component' => $block['component'], 'last_seen' => $block['updated_at']];
    }
}
