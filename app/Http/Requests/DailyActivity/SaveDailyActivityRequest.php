<?php

declare(strict_types=1);

namespace App\Http\Requests\DailyActivity;

use App\Enums\DailyActivityCategory;
use App\Enums\DailyActivityProgressStatus;
use App\Services\DailyActivity\DailyActivityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Draft save / submit of the signed-in employee's own daily activity.
 *
 * The employee, the date's organization / unit / position, the status and
 * every reviewer field are derived on the server. Sending any of them is a
 * tampering attempt and is rejected outright rather than silently dropped,
 * so a forged request fails loudly and is visible in logs.
 *
 * Field shape is validated here; business rules (dates, lateness, required
 * output on submit, task ownership) live in DailyActivityService.
 */
class SaveDailyActivityRequest extends FormRequest
{
    /** Server-owned fields that must never arrive in the payload. */
    public const PROTECTED_FIELDS = [
        'employee_id',
        'employee_assignment_id',
        'organization_id',
        'organization_unit_id',
        'position_id',
        'status',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_comment',
        'is_late',
        'activity_date',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('daily_activities.create') ?? false;
    }

    public function rules(): array
    {
        $rules = [
            'action' => ['required', Rule::in(['draft', 'submit'])],
            'items' => ['present', 'array', 'max:'.DailyActivityService::MAX_ITEMS],
            'items.*.id' => ['nullable', 'uuid'],
            'items.*.activity_category' => ['nullable', Rule::in(DailyActivityCategory::values())],
            'items.*.position_service_id' => ['nullable', 'uuid'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:5000'],
            'items.*.output_result' => ['nullable', 'string', 'max:5000'],
            'items.*.progress_status' => ['required', Rule::in(DailyActivityProgressStatus::values())],
            'items.*.started_at' => ['nullable', 'date_format:H:i'],
            'items.*.ended_at' => ['nullable', 'date_format:H:i'],
            'items.*.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'items.*.unit_of_measure' => ['nullable', 'string', 'max:64'],
            'items.*.challenge_issue' => ['nullable', 'string', 'max:5000'],
            'items.*.next_action' => ['nullable', 'string', 'max:5000'],
            // EPMS: ownership and dates are verified by DailyActivityService.
            'items.*.employee_performance_item_id' => ['nullable', 'uuid'],
            'late_reason' => ['nullable', 'string', 'max:2000'],
        ];

        foreach (self::PROTECTED_FIELDS as $field) {
            $rules[$field] = ['prohibited'];
            $rules["items.*.{$field}"] = ['prohibited'];
        }

        // EPMS hooks are not employee-writable either.
        $rules['items.*.performance_activity_id'] = ['prohibited'];
        $rules['items.*.kpi_id'] = ['prohibited'];
        $rules['items.*.reviewer_note'] = ['prohibited'];

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'items.*.title' => __('daily-activities.fields.title'),
            'items.*.description' => __('daily-activities.fields.description'),
            'items.*.output_result' => __('daily-activities.fields.output_result'),
        ];
    }
}
