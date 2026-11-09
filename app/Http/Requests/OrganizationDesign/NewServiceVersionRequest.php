<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;

/** A new version starts from the published content; only what changes is sent. */
class NewServiceVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('version', $this->route('service')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Service $service */
        $service = $this->route('service');
        $rules = ServiceRules::rules($service->organization_id, partial: true);
        $rules['effective_from'] = ['required', 'date_format:Y-m-d'];
        unset($rules['code']);

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ServiceRules::attributes();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ServiceRules::messages();
    }
}
