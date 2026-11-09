<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLegacyBaselineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('baseline')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return LegacyBaselineRules::rules(partial: true);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return LegacyBaselineRules::attributes();
    }
}
