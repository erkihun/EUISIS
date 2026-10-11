<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use Illuminate\Foundation\Http\FormRequest;

/** A new version starts from the published content; only what changes is sent. */
class NewMandateVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('version', $this->route('mandate')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = MandateRules::rules(partial: true);
        $rules['effective_from'] = ['required', 'date_format:Y-m-d'];
        unset($rules['code']); // the code names the lineage and never changes

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return MandateRules::attributes();
    }
}
