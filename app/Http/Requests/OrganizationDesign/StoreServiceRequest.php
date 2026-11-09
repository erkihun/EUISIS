<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\Service;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->can('create', Service::class)) {
            return false;
        }

        return app(OrganizationScopeService::class)->canAccess($user, $this->string('organization_id')->toString() ?: null);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            ...ServiceRules::rules($this->string('organization_id')->toString()),
        ];
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
