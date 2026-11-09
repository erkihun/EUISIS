<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\InstitutionMandate;
use App\Models\OrganizationFunction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FunctionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $function = $this->route('function');
        if ($function instanceof OrganizationFunction) {
            return $user?->can('update', $function) ?? false;
        }
        $mandate = $this->route('mandate');

        return $mandate instanceof InstitutionMandate && ($user?->can('manageFunctions', $mandate) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $function = $this->route('function');
        /** @var InstitutionMandate $mandate */
        $mandate = $function instanceof OrganizationFunction ? $function->mandate : $this->route('mandate');
        $partial = $function instanceof OrganizationFunction;
        $required = $partial ? 'sometimes' : 'required';

        return [
            // A function is created under the route's mandate; a different mandate id is a mistake, not a choice.
            'mandate_id' => ['nullable', 'uuid', Rule::in([$mandate->getKey()])],
            'code' => [$required, 'string', 'max:40', Rule::unique('organization_functions', 'code')->where('organization_id', $mandate->organization_id)->whereNull('deleted_at')->ignore($function)],
            'title_en' => [$required, 'string', 'max:255'],
            'title_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'legal_reference' => ['nullable', 'string', 'max:255'],
            'sequence' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'parent_function_id' => ['nullable', 'uuid'],
            'power_id' => ['nullable', 'uuid'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['mandate_id.in' => __('organization-design.function_mandate_mismatch')];
    }
}
