<?php

declare(strict_types=1);

namespace App\Services\Intelligence\System\Detection;

use App\Services\Intelligence\IntelligenceSettings;
use App\Services\Intelligence\System\Telemetry\SignalRepository;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Runs every detection rule over the current window and hands the findings
 * to the IncidentManager. Rules are isolated: one that throws is reported and
 * skipped, and its incidents are left untouched (never auto-cleared by a
 * rule that did not run).
 */
final class DetectionEngine
{
    /** @param  iterable<DetectionRule>  $rules */
    public function __construct(
        private readonly iterable $rules,
        private readonly SignalRepository $signals,
        private readonly IntelligenceSettings $settings,
        private readonly IncidentManager $incidents,
    ) {}

    /** @return array{findings: int, rules: int, failed_rules: list<string>, incidents: array{opened: int, recurred: int, updated: int, mitigated: int, resolved: int}} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $window = new DetectionWindow($now, $this->signals, $this->settings);
        $findings = [];
        $evaluated = [];
        $failed = [];

        foreach ($this->rules as $rule) {
            try {
                foreach ($rule->evaluate($window) as $finding) {
                    $findings[] = $finding;
                }
                $evaluated[] = $rule->key();
            } catch (Throwable $e) {
                report($e);
                $failed[] = $rule->key();
            }
        }

        return [
            'findings' => count($findings),
            'rules' => count($evaluated),
            'failed_rules' => $failed,
            'incidents' => $this->incidents->apply($findings, $evaluated, $now),
        ];
    }
}
