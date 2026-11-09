<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationDesign;

use App\Models\LegalSource;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Foundation\Http\FormRequest;

class StoreLegalSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->can('create', LegalSource::class)) {
            return false;
        }

        $organizationId = $this->string('organization_id')->toString() ?: null;

        // WHERE: a city-level source (no organization) needs the catalog
        // grant; an institution's source needs that institution in scope.
        return $organizationId === null
            ? $user->can('legal-sources.manageCatalog')
            : $user->can('legal-sources.create') && app(OrganizationScopeService::class)->canAccess($user, $organizationId);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            ...LegalSourceRules::rules(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return LegalSourceRules::attributes();
    }
}
