<?php

declare(strict_types=1);

namespace Modules\Assessment\Application\Services;

use Illuminate\Validation\Rule;
use Modules\Assessment\Domain\Enums\EvaluatorType;
use Modules\Assessment\Domain\Enums\InputMode;
use Modules\Assessment\Domain\Enums\PeriodType;
use Modules\Assessment\Domain\Enums\ScoringMethod;
use Modules\Assessment\Domain\Enums\SelectionMethod;
use Modules\Assessment\Domain\Enums\TargetType;

/**
 * The validation rules of a form version (its sections, criteria, options,
 * target rules and evaluator schemes), shared by the form builder's request
 * and the form importer.
 */
final class AssessmentFormVersionRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $decimal = ['nullable', 'numeric', 'min:0', 'max:99999999'];
        $text = ['nullable', 'string', 'max:5000'];

        return [
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => $text,
            'description_am' => $text,
            'instructions_en' => $text,
            'instructions_am' => $text,
            'period_type' => ['nullable', Rule::in(PeriodType::values())],
            'scoring_method' => ['required', Rule::in(ScoringMethod::values())],
            'max_total_score' => $decimal,
            'overall_contribution_weight' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'result_scale_id' => ['nullable', 'uuid', Rule::exists('performance_rating_scales', 'id')->where('scale_type', 'RESULT')],
            'acknowledgement_required' => ['boolean'],
            'review_required' => ['boolean'],
            'show_option_scores' => ['boolean'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],

            'sections' => ['present', 'array', 'max:50'],
            'sections.*.code' => ['nullable', 'string', 'max:40'],
            'sections.*.title_en' => ['required', 'string', 'max:255'],
            'sections.*.title_am' => ['nullable', 'string', 'max:255'],
            'sections.*.description_en' => $text,
            'sections.*.description_am' => $text,
            'sections.*.weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sections.*.max_score' => $decimal,
            'sections.*.is_required' => ['boolean'],

            'sections.*.criteria' => ['present', 'array', 'max:100'],
            'sections.*.criteria.*.competency_id' => ['nullable', 'uuid', Rule::exists('competencies', 'id')],
            'sections.*.criteria.*.code' => ['nullable', 'string', 'max:40'],
            'sections.*.criteria.*.title_en' => ['required', 'string', 'max:255'],
            'sections.*.criteria.*.title_am' => ['nullable', 'string', 'max:255'],
            'sections.*.criteria.*.description_en' => $text,
            'sections.*.criteria.*.description_am' => $text,
            'sections.*.criteria.*.max_score' => $decimal,
            'sections.*.criteria.*.weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sections.*.criteria.*.is_required' => ['boolean'],
            'sections.*.criteria.*.comment_mode' => ['required', Rule::in(InputMode::values())],
            'sections.*.criteria.*.evidence_mode' => ['required', Rule::in(InputMode::values())],

            'sections.*.criteria.*.options' => ['present', 'array', 'max:20'],
            'sections.*.criteria.*.options.*.label_en' => ['nullable', 'string', 'max:120'],
            'sections.*.criteria.*.options.*.label_am' => ['nullable', 'string', 'max:120'],
            'sections.*.criteria.*.options.*.description_en' => $text,
            'sections.*.criteria.*.options.*.description_am' => $text,
            'sections.*.criteria.*.options.*.score' => ['required', 'numeric', 'min:0', 'max:99999999'],

            'target_rules' => ['present', 'array', 'max:200'],
            'target_rules.*.target_type' => ['required', Rule::in(TargetType::values())],
            'target_rules.*.target_id' => ['nullable', 'uuid'],
            'target_rules.*.target_value' => ['nullable', 'string', 'max:120'],
            'target_rules.*.include_descendants' => ['boolean'],
            'target_rules.*.effect' => ['required', Rule::in(['include', 'exclude'])],
            'target_rules.*.priority' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
            'target_rules.*.effective_from' => ['nullable', 'date_format:Y-m-d'],
            'target_rules.*.effective_to' => ['nullable', 'date_format:Y-m-d'],

            'evaluators' => ['present', 'array', 'max:10'],
            'evaluators.*.evaluator_type' => ['required', 'distinct', Rule::in(EvaluatorType::values())],
            'evaluators.*.required_count' => ['required', 'integer', 'min:1', 'max:50'],
            'evaluators.*.contribution_weight' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'evaluators.*.selection_method' => ['required', Rule::in(SelectionMethod::values())],
            'evaluators.*.aggregation_method' => ['nullable', Rule::in(['average'])],
            'evaluators.*.is_anonymous' => ['boolean'],
            'evaluators.*.requires_review' => ['boolean'],
        ];
    }
}
