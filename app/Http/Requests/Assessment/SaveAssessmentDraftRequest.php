<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

use App\Enums\Assessment\EvaluatorType;
use App\Enums\Assessment\InputMode;
use App\Enums\Assessment\PeriodType;
use App\Enums\Assessment\ScoringMethod;
use App\Enums\Assessment\SelectionMethod;
use App\Enums\Assessment\TargetType;
use App\Models\AssessmentFormVersion;
use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The whole draft of a form version: header, sections → criteria → rating
 * options, target rules and evaluator scheme. Shape only; whether the form
 * is consistent enough to publish is AssessmentScoringService's job, so a
 * half-built draft can still be saved.
 */
class SaveAssessmentDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $version = $this->route('version');

        return $version instanceof AssessmentFormVersion && $this->user()->can('update', $version->form);
    }

    public function rules(): array
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

    /**
     * Target rules name real master data, and an organization's form only
     * targets that organization: a rule cannot reach into another one.
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $version = $this->route('version');
            $owner = $version instanceof AssessmentFormVersion ? $version->form->organization_id : null;

            foreach ((array) $this->input('target_rules', []) as $i => $rule) {
                $id = $rule['target_id'] ?? null;
                $type = $rule['target_type'] ?? null;
                if (! is_string($id) || $id === '') {
                    continue;
                }

                $organizationOf = match ($type) {
                    'position' => Position::query()->whereKey($id)->value('organization_id'),
                    'organization_unit' => OrganizationUnit::query()->whereKey($id)->value('organization_id'),
                    'organization' => Organization::query()->whereKey($id)->exists() ? $id : null,
                    'occupation' => Occupation::query()->whereKey($id)->exists() ? 'shared' : null,
                    default => 'shared',
                };

                if ($organizationOf === null) {
                    $validator->errors()->add("target_rules.{$i}.target_id", __('assessments.validation.target_missing'));
                } elseif ($owner !== null && $organizationOf !== 'shared' && $organizationOf !== $owner) {
                    $validator->errors()->add("target_rules.{$i}.target_id", __('assessments.validation.target_outside'));
                }
            }
        }];
    }

    /** @return array<string, mixed> with defaults filled in */
    public function draft(): array
    {
        $data = $this->validated();
        foreach ($data['target_rules'] as $i => $rule) {
            $data['target_rules'][$i]['priority'] = (int) ($rule['priority'] ?? 0);
            $data['target_rules'][$i]['include_descendants'] = (bool) ($rule['include_descendants'] ?? true);
        }
        foreach ($data['evaluators'] as $i => $scheme) {
            $data['evaluators'][$i]['aggregation_method'] = $scheme['aggregation_method'] ?? 'average';
        }

        return $data;
    }
}
