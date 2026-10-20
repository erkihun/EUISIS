<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PublicFeedbackTargetType;
use App\Enums\ServiceFeedbackType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePublicFeedbackCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Scope (city-wide versus one institution) is checked in the controller
        // against the organization the category belongs to.
        return $this->user()?->hasAnyPermission(['service_feedback.manage_categories']) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_am' => ['nullable', 'string', 'max:2000'],
            'submission_types' => ['required', 'array', 'min:1'],
            'submission_types.*' => [Rule::in(array_column(ServiceFeedbackType::cases(), 'value'))],
            'target_types' => ['required', 'array', 'min:1'],
            'target_types.*' => [Rule::in(PublicFeedbackTargetType::values())],
            'organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
            'is_active' => ['nullable', 'boolean'],
            'is_public_selectable' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
