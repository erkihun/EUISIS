<?php

declare(strict_types=1);

namespace Modules\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Leave\Domain\Models\LeaveRequest;

/**
 * The shape of a leave request, from the employee.
 * Whether it may be granted (type, dates, balance, limits, overlap) is
 * decided by LeaveRequestService; who may send it, by the controller.
 */
class SaveLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'uuid'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'is_half_day' => ['sometimes', 'boolean'],
            'half_day_part' => ['nullable', Rule::in([LeaveRequest::HALF_MORNING, LeaveRequest::HALF_AFTERNOON])],
            'expected_delivery_on' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'contact_during_leave' => ['nullable', 'string', 'max:255'],
            'documents' => ['sometimes', 'array', 'max:5'],
            'documents.*' => ['file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'leave_type_id' => __('leave::messages.fields.leave_type'),
            'starts_on' => __('leave::messages.fields.starts_on'),
            'ends_on' => __('leave::messages.fields.ends_on'),
            'expected_delivery_on' => __('leave::messages.fields.expected_delivery_on'),
            'reason' => __('leave::messages.fields.reason'),
            'contact_during_leave' => __('leave::messages.fields.contact'),
            'documents' => __('leave::messages.fields.documents'),
            'documents.*' => __('leave::messages.fields.documents'),
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->safe()->except(['documents']);
    }
}
