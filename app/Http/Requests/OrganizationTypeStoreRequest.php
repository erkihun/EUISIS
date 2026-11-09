<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrganizationTypeCategory;
use App\Models\OrganizationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class OrganizationTypeStoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'prefix' => filled($this->input('prefix')) ? mb_strtoupper(trim((string) $this->input('prefix')), 'UTF-8') : null,
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', OrganizationType::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:64', 'unique:organization_types,code'],
            'prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_\-\x{1200}-\x{137F}\x{1380}-\x{139F}\x{2D80}-\x{2DDF}\x{AB00}-\x{AB2F}]+$/u'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:1000'],
            'description_am' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
            'level_order' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'string', new Enum(OrganizationTypeCategory::class)],
            'parent_allowed_types' => ['nullable', 'array'],
            'parent_allowed_types.*' => ['string', 'exists:organization_types,code'],
        ];
    }

    public function attributes(): array
    {
        return [
            'prefix' => __('organization-types.prefix'),
            'level_order' => __('organization-types.level_order'),
            'category' => __('organization-types.category'),
            'parent_allowed_types' => __('organization-types.parent_allowed_types'),
        ];
    }

    public function messages(): array
    {
        return [
            'prefix.regex' => __('organization-types.prefix_invalid'),
        ];
    }
}
