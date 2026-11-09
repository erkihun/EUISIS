<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\Process;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Foundation\Http\FormRequest;

class StoreProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->can('create', Process::class)) {
            return false;
        }

        return app(OrganizationScopeService::class)->canAccess($user, $this->string('organization_id')->toString() ?: null);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            ...ProcessRules::rules($this->string('organization_id')->toString()),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ProcessRules::attributes();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ProcessRules::messages();
    }
}
