<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Enums\RoundingRule;
use App\Models\WorkforcePlanningMethodology;
use App\Services\OrganizationDesign\ManpowerCalculationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A methodology draft, edit or new version. Parameters are checked here
 * against the strategy's schema (plain numbers in range, no unknown keys,
 * never an expression); completeness is checked at publish time (D-6).
 */
class MethodologyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $methodology = $this->route('methodology');
        $user = $this->user();
        if (! $methodology instanceof WorkforcePlanningMethodology) {
            return $user?->can('create', WorkforcePlanningMethodology::class) ?? false;
        }

        return $user?->can($this->isVersion() ? 'version' : 'update', $methodology) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $methodology = $this->route('methodology');
        $partial = $methodology instanceof WorkforcePlanningMethodology;
        $required = $partial ? 'sometimes' : 'required';
        $strategies = app(ManpowerCalculationService::class)->strategies();

        $rules = [
            'code' => [$required, 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/',
                Rule::unique('workforce_planning_methodologies', 'code')->whereNull('deleted_at')->when($partial, fn ($rule) => $rule->where(fn ($q) => $q->where('lineage_key', '<>', $methodology->lineage_key)))],
            'name_en' => [$required, 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'calculation_type' => [$required, Rule::in($strategies)],
            'parameters' => ['nullable', 'array', 'max:20'],
            'parameters.*' => ['nullable', 'string', 'max:40'],
            'rounding_rule' => ['nullable', Rule::in(RoundingRule::values())],
            'effective_from' => [$required, 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
        if ($this->isVersion()) {
            $rules['effective_from'] = ['required', 'date_format:Y-m-d'];
            $rules['calculation_type'] = ['prohibited'];
            unset($rules['code']);
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty() || ! $this->has('parameters')) {
                return;
            }
            $methodology = $this->route('methodology');
            $type = $this->input('calculation_type') ?? ($methodology instanceof WorkforcePlanningMethodology ? $methodology->calculation_type : null);
            if ($type === null) {
                return;
            }
            foreach (app(ManpowerCalculationService::class)->parameterErrors($type, (array) $this->input('parameters', []), complete: false) as $key => $message) {
                $v->errors()->add($key, $message);
            }
        });
    }

    private function isVersion(): bool
    {
        return str_ends_with((string) $this->route()?->getName(), 'versions.store');
    }
}
