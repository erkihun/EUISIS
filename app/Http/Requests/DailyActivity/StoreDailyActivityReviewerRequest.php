<?php

declare(strict_types=1);

namespace App\Http\Requests\DailyActivity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A reviewer assignment. Scope (the actor may only grant authority inside
 * organizations they administer) and consistency (unit and employee belong
 * to the organization) are checked in the controller against the database.
 */
class StoreDailyActivityReviewerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('daily_activities.manage_reviewers') ?? false;
    }

    public function rules(): array
    {
        return [
            'reviewer_user_id' => ['required', 'integer', 'exists:users,id'],
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'],
            'include_sub_units' => ['boolean'],
            'employee_id' => ['nullable', 'uuid', 'exists:employees,id'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }
}
