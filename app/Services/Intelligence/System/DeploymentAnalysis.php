<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System;

use App\Models\IntelligenceDeployment;
use App\Models\IntelligenceErrorFingerprint;
use App\Models\IntelligenceIncident;
use App\Services\Intelligence\Insight;
use App\Services\Intelligence\System\Detection\IncidentManager;
use App\Services\SituationRoom\SituationContext;
use App\Services\SituationRoom\SituationLabel;
use App\Services\SituationRoom\SituationPanel;

/**
 * Deployment Analysis (brief §44, §107): recorded releases and what started
 * within an hour of each — incidents and new error signatures. Wording stays
 * "began N minutes after": a correlation, never a cause. Without recorded
 * deployments the area says NEEDS_DEPLOYMENT_CONFIGURATION.
 */
final class DeploymentAnalysis extends SystemAnalysis
{
    public function panels(SituationContext $context): array
    {
        return [
            'deployment_headline' => fn (): SituationPanel => $this->headline($context),
            'deployments_table' => fn (): SituationPanel => $this->table($context),
        ];
    }

    public function insights(SituationContext $context, array $panels): array
    {
        return IntelligenceIncident::query()->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)->whereNotNull('deployment_id')->with('deployment')
            ->orderByDesc('detected_at')->limit(5)->get()
            ->map(fn (IntelligenceIncident $i): Insight => $this->insight(
                'deployment_correlated',
                $i->severity,
                ['reference' => $i->reference, 'release' => $i->deployment?->release ?? '', 'minutes' => $i->deployment !== null ? (int) $i->deployment->deployed_at->diffInMinutes($i->detected_at) : 0],
                [
                    $this->evidence('release', $i->deployment?->release),
                    $this->evidence('deployed_at', $i->deployment?->deployed_at?->toIso8601String()),
                    $this->evidence('detected_at', $i->detected_at->toIso8601String()),
                ],
                null,
                'medium',
                route('intelligence.incidents.show', $i->id),
                $this->source('deployments'),
            ))->all();
    }

    public function headline(SituationContext $context): SituationPanel
    {
        if (IntelligenceDeployment::query()->doesntExist()) {
            return $this->notice('deployment_headline', $this->source('deployments'), 'NEEDS_DEPLOYMENT_CONFIGURATION', 3);
        }
        [$from, $to] = $this->window($context);
        $deployments = IntelligenceDeployment::query()->whereBetween('deployed_at', [$from->toDateTimeString(), $to->toDateTimeString()])->get();
        $withIncidents = $deployments->filter(fn (IntelligenceDeployment $d): bool => $this->incidentsAfter($d) > 0)->count();

        return SituationPanel::kpis('deployment_headline', $this->title('deployment_headline'), $this->source('deployments'), [
            $this->kpi('deployments_count', $deployments->count()),
            $this->kpi('deployments_with_incidents', $withIncidents, ['state' => $withIncidents > 0 ? 'attention' : 'clear']),
            $this->kpi('active_correlated_incidents', IntelligenceIncident::query()->whereIn('status', IntelligenceIncident::ACTIVE_STATUSES)->whereNotNull('deployment_id')->count()),
        ], $this->hint('deployment_headline'));
    }

    private function table(SituationContext $context): SituationPanel
    {
        if (IntelligenceDeployment::query()->doesntExist()) {
            return $this->notice('deployments_table', $this->source('deployments'), 'NEEDS_DEPLOYMENT_CONFIGURATION', 3);
        }
        $rows = IntelligenceDeployment::query()->orderByDesc('deployed_at')->limit(20)->get()->map(fn (IntelligenceDeployment $d): array => [
            'key' => $d->id,
            'cells' => [
                'release' => $d->release,
                'commit' => $d->commit_hash !== null ? substr($d->commit_hash, 0, 10) : '',
                'environment' => $d->environment,
                'deployed_at' => $d->deployed_at->toIso8601String(),
                'incidents_after' => $this->incidentsAfter($d),
                'new_errors_after' => IntelligenceErrorFingerprint::query()->whereBetween('first_seen_at', [$d->deployed_at->toDateTimeString(), $d->deployed_at->addMinutes(IncidentManager::DEPLOYMENT_WINDOW_MINUTES)->toDateTimeString()])->count(),
            ],
        ])->all();

        return SituationPanel::table('deployments_table', $this->title('deployments_table'), $this->hint('deployments_table'), $this->source('deployments'), [
            ['key' => 'release', 'label' => SituationLabel::key(self::PREFIX.'columns.release'), 'type' => 'text'],
            ['key' => 'commit', 'label' => SituationLabel::key(self::PREFIX.'columns.commit'), 'type' => 'text'],
            ['key' => 'environment', 'label' => SituationLabel::key(self::PREFIX.'columns.environment'), 'type' => 'text'],
            ['key' => 'deployed_at', 'label' => SituationLabel::key(self::PREFIX.'columns.deployed_at'), 'type' => 'date'],
            ['key' => 'incidents_after', 'label' => SituationLabel::key(self::PREFIX.'columns.incidents_after'), 'type' => 'number'],
            ['key' => 'new_errors_after', 'label' => SituationLabel::key(self::PREFIX.'columns.new_errors_after'), 'type' => 'number'],
        ], $rows, span: 3);
    }

    private function incidentsAfter(IntelligenceDeployment $d): int
    {
        return IntelligenceIncident::query()->whereBetween('detected_at', [$d->deployed_at->toDateTimeString(), $d->deployed_at->addMinutes(IncidentManager::DEPLOYMENT_WINDOW_MINUTES)->toDateTimeString()])->count();
    }
}
