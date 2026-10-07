<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\Assessment\ScoringMethod;
use App\Enums\Assessment\TargetType;
use App\Models\AssessmentCriterion;
use App\Models\AssessmentFormSection;
use App\Models\AssessmentFormVersion;
use InvalidArgumentException;

/**
 * Scoring for assessment form versions (docs/assessment-scoring.md).
 *
 * Server-side and decimal (bcmath), never float, never a formula typed by an
 * administrator: the four scoring methods are predefined here.
 *
 *   criterion score   = the score of the selected rating option (snapshotted)
 *   section raw       = sum of its criterion scores; section max = sum of criterion maxima
 *   raw total         = sum of section raws;       form max    = sum of section maxima
 *   percentage        = raw total / form max × 100
 *   weighted          = Σ section% × section weight / 100   (weights sum to 100)
 *   contribution      = percentage (or weighted) × overall contribution weight / 100
 *
 * Several evaluator types are combined by their configured weights, which
 * must sum to 100; they are never silently rescaled or blindly averaged.
 */
class AssessmentScoringService
{
    public const SCALE = 10;

    public const STORED_SCALE = 4;

    /** The highest score a criterion permits: its configured maximum, else its best option. */
    public function criterionMax(AssessmentCriterion $criterion): string
    {
        if ($criterion->max_score !== null) {
            return $this->decimal($criterion->max_score);
        }

        $best = '0';
        foreach ($criterion->options as $option) {
            if (bccomp($this->decimal($option->score), $best, self::SCALE) > 0) {
                $best = $this->decimal($option->score);
            }
        }

        return $best;
    }

    public function sectionMax(AssessmentFormSection $section): string
    {
        $sum = '0';
        foreach ($section->criteria as $criterion) {
            $sum = bcadd($sum, $this->criterionMax($criterion), self::SCALE);
        }

        return $sum;
    }

    public function versionMax(AssessmentFormVersion $version): string
    {
        $sum = '0';
        foreach ($version->sections as $section) {
            $sum = bcadd($sum, $this->sectionMax($section), self::SCALE);
        }

        return $sum;
    }

    /**
     * The score of one answered criterion, refused when above its maximum.
     */
    public function criterionScore(AssessmentCriterion $criterion, mixed $score): string
    {
        $value = $this->decimal($score);
        if (bccomp($value, $this->criterionMax($criterion), self::SCALE) > 0) {
            throw new InvalidArgumentException('The selected score exceeds the criterion maximum.');
        }

        return $value;
    }

    /**
     * Score a set of answers against a version (loaded with sections,
     * criteria and options).
     *
     * @param  array<string, mixed>  $scores  criterion id => selected (snapshotted) score
     * @return array{raw: string, max: string, percentage: ?string, weighted: ?string, final: ?string, contribution: ?string, sections: array<string, array{raw: string, max: string, percentage: ?string}>}
     */
    public function score(AssessmentFormVersion $version, array $scores): array
    {
        $sections = [];
        $raw = '0';
        $max = '0';
        $weighted = null;
        $weightsComplete = $version->sections->isNotEmpty() && $version->sections->every(fn (AssessmentFormSection $s): bool => $s->weight !== null);

        foreach ($version->sections as $section) {
            $sectionRaw = '0';
            foreach ($section->criteria as $criterion) {
                if (array_key_exists($criterion->id, $scores) && $scores[$criterion->id] !== null && $scores[$criterion->id] !== '') {
                    $sectionRaw = bcadd($sectionRaw, $this->criterionScore($criterion, $scores[$criterion->id]), self::SCALE);
                }
            }
            $sectionMax = $this->sectionMax($section);
            $sectionPercent = $this->sectionPercent($section, $scores, $sectionRaw, $sectionMax);

            $sections[$section->id] = ['raw' => $sectionRaw, 'max' => $sectionMax, 'percentage' => $sectionPercent];
            $raw = bcadd($raw, $sectionRaw, self::SCALE);
            $max = bcadd($max, $sectionMax, self::SCALE);

            if ($weightsComplete && $sectionPercent !== null) {
                $weighted = bcadd($weighted ?? '0', bcdiv(bcmul($sectionPercent, $this->decimal($section->weight), self::SCALE), '100', self::SCALE), self::SCALE);
            }
        }

        $percentage = bccomp($max, '0', self::SCALE) > 0 ? bcdiv(bcmul($raw, '100', self::SCALE), $max, self::SCALE) : null;
        $basis = $version->scoring_method === ScoringMethod::WeightedScore ? $weighted : $percentage;
        $contribution = $basis !== null && $version->overall_contribution_weight !== null
            ? bcdiv(bcmul($basis, $this->decimal($version->overall_contribution_weight), self::SCALE), '100', self::SCALE)
            : null;

        $final = match ($version->scoring_method) {
            ScoringMethod::RawScore => $raw,
            ScoringMethod::PercentOfMax => $percentage,
            ScoringMethod::WeightedScore => $weighted,
            ScoringMethod::ContributionWeight => $contribution,
            default => $percentage,
        };

        return [
            'raw' => $raw,
            'max' => $max,
            'percentage' => $percentage,
            'weighted' => $weighted,
            'final' => $final,
            'contribution' => $contribution,
            'sections' => $sections,
        ];
    }

    /**
     * Combine evaluator components by their configured weights.
     *
     * @param  array<string, array{percentage: ?string, weight: ?string}>  $components  evaluator type => component
     * @return string|null null when a weighted component is missing
     */
    public function combine(array $components): ?string
    {
        if ($components === []) {
            return null;
        }

        if (count($components) === 1) {
            return array_values($components)[0]['percentage'];
        }

        $total = '0';
        $weights = '0';
        foreach ($components as $component) {
            if ($component['percentage'] === null || $component['weight'] === null) {
                return null;
            }
            $total = bcadd($total, bcdiv(bcmul($this->decimal($component['percentage']), $this->decimal($component['weight']), self::SCALE), '100', self::SCALE), self::SCALE);
            $weights = bcadd($weights, $this->decimal($component['weight']), self::SCALE);
        }

        // Weights are validated to sum to 100 before a version is published.
        if (bccomp($weights, '100', self::SCALE) !== 0) {
            throw new InvalidArgumentException('Evaluator contribution weights must sum to 100.');
        }

        return $total;
    }

    /** Average of several evaluators of one type (the only aggregation method so far). */
    public function average(array $percentages): ?string
    {
        $values = array_values(array_filter($percentages, fn ($value): bool => $value !== null));
        if ($values === []) {
            return null;
        }

        $sum = '0';
        foreach ($values as $value) {
            $sum = bcadd($sum, $this->decimal($value), self::SCALE);
        }

        return bcdiv($sum, (string) count($values), self::SCALE);
    }

    public function round(?string $value, int $scale = self::STORED_SCALE): ?string
    {
        if ($value === null) {
            return null;
        }

        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', self::SCALE) < 0 ? bcsub($value, $half, $scale) : bcadd($value, $half, $scale);
    }

    /**
     * Everything that would make a version inconsistent, before it may be
     * published. Empty when the version is valid.
     *
     * @return array<string, string> field path => message
     */
    public function validateConfiguration(AssessmentFormVersion $version): array
    {
        $errors = [];

        if (blank($version->name_en) && blank($version->name_am)) {
            $errors['name_en'] = __('assessments.validation.name_required');
        }
        if ($version->sections->isEmpty()) {
            $errors['sections'] = __('assessments.validation.section_required');
        }

        foreach ($version->sections->values() as $s => $section) {
            if ($section->criteria->isEmpty()) {
                $errors["sections.{$s}.criteria"] = __('assessments.validation.criterion_required', ['section' => $section->title_en]);
            }

            foreach ($section->criteria->values() as $c => $criterion) {
                $path = "sections.{$s}.criteria.{$c}";
                if ($criterion->options->count() < 2) {
                    $errors["{$path}.options"] = __('assessments.validation.options_required', ['criterion' => $criterion->title_en]);

                    continue;
                }
                $best = '0';
                foreach ($criterion->options as $option) {
                    if (bccomp($this->decimal($option->score), '0', self::SCALE) < 0) {
                        $errors["{$path}.options"] = __('assessments.validation.negative_score', ['criterion' => $criterion->title_en]);
                    }
                    $best = bccomp($this->decimal($option->score), $best, self::SCALE) > 0 ? $this->decimal($option->score) : $best;
                }
                if ($criterion->max_score !== null && bccomp($this->decimal($criterion->max_score), $best, self::SCALE) !== 0) {
                    // Above the best option, the maximum is unreachable; below it, an option is out of range.
                    $errors["{$path}.max_score"] = __('assessments.validation.criterion_max', ['criterion' => $criterion->title_en, 'best' => $this->plain($best)]);
                }
            }

            if ($section->max_score !== null && $section->criteria->isNotEmpty()
                && bccomp($this->decimal($section->max_score), $this->sectionMax($section), self::SCALE) !== 0) {
                $errors["sections.{$s}.max_score"] = __('assessments.validation.section_max', ['section' => $section->title_en, 'sum' => $this->plain($this->sectionMax($section))]);
            }

            $criterionWeights = $section->criteria->pluck('weight')->filter(fn ($weight) => $weight !== null);
            if ($criterionWeights->isNotEmpty() && ($criterionWeights->count() !== $section->criteria->count() || bccomp($this->sum($criterionWeights->all()), '100', self::SCALE) !== 0)) {
                $errors["sections.{$s}.criteria_weights"] = __('assessments.validation.criterion_weights', ['section' => $section->title_en]);
            }
        }

        if ($version->max_total_score !== null && $version->sections->isNotEmpty()
            && bccomp($this->decimal($version->max_total_score), $this->versionMax($version), self::SCALE) !== 0) {
            $errors['max_total_score'] = __('assessments.validation.form_max', ['sum' => $this->plain($this->versionMax($version))]);
        }

        if ($version->scoring_method === ScoringMethod::WeightedScore) {
            $weights = $version->sections->pluck('weight');
            if ($weights->contains(null) || bccomp($this->sum($weights->all()), '100', self::SCALE) !== 0) {
                $errors['sections_weights'] = __('assessments.validation.section_weights');
            }
        }

        $contribution = $version->overall_contribution_weight;
        if ($version->scoring_method === ScoringMethod::ContributionWeight && $contribution === null) {
            $errors['overall_contribution_weight'] = __('assessments.validation.contribution_required');
        }
        if ($contribution !== null && (bccomp($this->decimal($contribution), '0', self::SCALE) <= 0 || bccomp($this->decimal($contribution), '100', self::SCALE) > 0)) {
            $errors['overall_contribution_weight'] = __('assessments.validation.contribution_range');
        }

        $schemes = $version->evaluatorSchemes;
        if ($schemes->isEmpty()) {
            $errors['evaluators'] = __('assessments.validation.evaluator_required');
        } elseif ($schemes->count() > 1) {
            $weights = $schemes->pluck('contribution_weight');
            if ($weights->contains(null) || bccomp($this->sum($weights->all()), '100', self::SCALE) !== 0) {
                $errors['evaluators'] = __('assessments.validation.evaluator_weights');
            }
        }

        $rules = $version->targetRules;
        if ($rules->where('effect', 'include')->isEmpty()) {
            $errors['target_rules'] = __('assessments.validation.target_required');
        }
        foreach ($rules->values() as $r => $rule) {
            $needsId = in_array($rule->target_type, [TargetType::Position, TargetType::Occupation, TargetType::Organization, TargetType::OrganizationUnit], true);
            $needsValue = in_array($rule->target_type, [TargetType::GradeLevel, TargetType::JobFamily], true);
            if (($needsId && blank($rule->target_id)) || ($needsValue && blank($rule->target_value))) {
                $errors["target_rules.{$r}"] = __('assessments.validation.target_incomplete');
            }
        }

        if ($version->effective_from !== null && $version->effective_to !== null && $version->effective_to->lt($version->effective_from)) {
            $errors['effective_to'] = __('assessments.validation.effective_dates');
        }

        return $errors;
    }

    /** @param array<int, mixed> $values */
    private function sum(array $values): string
    {
        $sum = '0';
        foreach ($values as $value) {
            $sum = bcadd($sum, $this->decimal($value ?? 0), self::SCALE);
        }

        return $sum;
    }

    /** @param array<string, mixed> $scores */
    private function sectionPercent(AssessmentFormSection $section, array $scores, string $raw, string $max): ?string
    {
        $weighted = $section->criteria->isNotEmpty() && $section->criteria->every(fn (AssessmentCriterion $c): bool => $c->weight !== null);

        if (! $weighted) {
            return bccomp($max, '0', self::SCALE) > 0 ? bcdiv(bcmul($raw, '100', self::SCALE), $max, self::SCALE) : null;
        }

        // Criterion weights within the section: Σ (score / max × weight).
        $total = '0';
        foreach ($section->criteria as $criterion) {
            $criterionMax = $this->criterionMax($criterion);
            $score = $scores[$criterion->id] ?? null;
            if ($score === null || $score === '' || bccomp($criterionMax, '0', self::SCALE) === 0) {
                continue;
            }
            $total = bcadd($total, bcdiv(bcmul($this->criterionScore($criterion, $score), $this->decimal($criterion->weight), self::SCALE), $criterionMax, self::SCALE), self::SCALE);
        }

        return $total;
    }

    private function plain(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }

    private function decimal(mixed $value): string
    {
        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }
        $text = trim((string) $value);

        if ($text === '' || ! is_numeric($text) || str_contains(strtolower($text), 'e')) {
            throw new InvalidArgumentException('Not a decimal number.');
        }

        return $text;
    }
}
