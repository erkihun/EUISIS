<?php

declare(strict_types=1);

namespace App\Services\Assessment\Execution;

use App\Enums\Assessment\ScoringMethod;
use App\Models\AssessmentRecord;
use App\Models\AssessmentResponse;
use App\Services\Assessment\AssessmentScoringService;

/**
 * Turns independent evaluator components into the record result
 * (docs/assessment-scoring-workflow.md):
 *
 *   criterion scores → section scores → raw total → normalized %   (per response, AssessmentScoringService)
 *   responses of one evaluator type → configured aggregation (average)  (only once all required responses are in)
 *   evaluator types → configured contribution weights (sum 100)
 *   combined % × overall contribution weight → contribution
 *
 * Raw totals and contribution are never mixed. Returns null while any
 * configured component is incomplete.
 */
class AssessmentResultCalculator
{
    public function __construct(private readonly AssessmentScoringService $scoring) {}

    /** @return array{percentage: string, contribution: ?string, breakdown: array<string, mixed>}|null */
    public function calculate(AssessmentRecord $record): ?array
    {
        $version = $record->version()->with('evaluatorSchemes')->first();
        $responses = $record->responses()->whereIn('status', AssessmentResponse::ACTIVE)->get();
        $basisKey = $version->scoring_method === ScoringMethod::WeightedScore ? 'weighted' : 'percentage';
        $components = [];
        $breakdown = [];

        foreach ($version->evaluatorSchemes as $scheme) {
            $type = $scheme->evaluator_type->value;
            $ofType = $responses->where('evaluator_type', $type);
            $submitted = $ofType->where('status', 'submitted');
            if ($submitted->count() < $scheme->required_count || $ofType->count() !== $submitted->count()) {
                return null;
            }
            $percentages = $submitted->map(fn (AssessmentResponse $r) => $r->score_snapshot[$basisKey] ?? null)->all();
            $raws = $submitted->map(fn (AssessmentResponse $r) => $r->score_snapshot['raw'] ?? null)->all();
            $percentage = $this->scoring->average($percentages);
            $components[$type] = ['percentage' => $percentage, 'weight' => $scheme->contribution_weight === null ? null : (string) $scheme->contribution_weight];
            $breakdown[] = [
                'type' => $type, 'required' => $scheme->required_count, 'submitted' => $submitted->count(), 'aggregation' => $scheme->aggregation_method ?? 'average',
                'weight' => $components[$type]['weight'], 'percentage' => $this->scoring->round($percentage),
                'raw_average' => $this->scoring->round($this->scoring->average($raws)), 'anonymous' => (bool) $scheme->is_anonymous,
            ];
        }

        $combined = $this->scoring->combine($components);
        if ($combined === null) {
            return null;
        }
        $contribution = $version->overall_contribution_weight === null ? null
            : bcdiv(bcmul($combined, (string) $version->overall_contribution_weight, AssessmentScoringService::SCALE), '100', AssessmentScoringService::SCALE);

        return [
            'percentage' => $this->scoring->round($combined),
            'contribution' => $this->scoring->round($contribution),
            'breakdown' => ['basis' => $basisKey, 'components' => $breakdown, 'form_version_id' => $version->id, 'overall_contribution_weight' => $version->overall_contribution_weight === null ? null : (string) $version->overall_contribution_weight],
        ];
    }
}
