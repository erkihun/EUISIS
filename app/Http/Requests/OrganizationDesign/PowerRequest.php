<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\InstitutionMandate;
use App\Models\OrganizationPower;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PowerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $power = $this->route('power');
        if ($power instanceof OrganizationPower) {
            return $user?->can('update', $power) ?? false;
        }
        $mandate = $this->route('mandate');

        return $mandate instanceof InstitutionMandate && ($user?->can('manageFunctions', $mandate) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $power = $this->route('power');
        $mandateId = $power instanceof OrganizationPower ? $power->mandate_id : $this->route('mandate')?->getKey();
        $partial = $power instanceof OrganizationPower;
        $required = $partial ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:40', Rule::unique('organization_powers', 'code')->where('mandate_id', $mandateId)->whereNull('deleted_at')->ignore($power)],
            'title_en' => [$required, 'string', 'max:255'],
            'title_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'legal_reference' => ['nullable', 'string', 'max:255'],
            'sequence' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }
}
