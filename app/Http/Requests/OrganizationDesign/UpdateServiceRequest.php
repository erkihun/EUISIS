<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('service')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Service $service */
        $service = $this->route('service');

        return ServiceRules::rules($service->organization_id, partial: true);
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
