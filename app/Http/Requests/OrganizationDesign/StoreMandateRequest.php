<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\InstitutionMandate;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Foundation\Http\FormRequest;

class StoreMandateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->can('create', InstitutionMandate::class)) {
            return false;
        }

        // WHERE: the designer must be allowed to act in the target organization.
        return app(OrganizationScopeService::class)->canAccess($user, $this->string('organization_id')->toString() ?: null);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            ...MandateRules::rules(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return MandateRules::attributes();
    }
}
