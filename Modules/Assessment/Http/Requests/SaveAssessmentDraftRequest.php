<?php

declare(strict_types=1);

namespace Modules\Assessment\Http\Requests;

use App\Models\Occupation;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Assessment\Application\Services\AssessmentFormVersionRules;
use Modules\Assessment\Domain\Models\AssessmentFormVersion;

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
        return AssessmentFormVersionRules::rules();
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
