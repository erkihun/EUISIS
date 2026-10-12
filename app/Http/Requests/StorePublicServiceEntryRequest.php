<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePublicServiceEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $complaint = $this->route('type') === 'complaint';

        return [
            'position_service_id' => ['nullable', 'uuid', Rule::exists('position_services', 'id')->whereNull('deleted_at')],
            'message' => ['required', 'string', 'max:5000'],
            'client_name' => ['nullable', 'string', 'max:120'],
            'client_contact' => ['nullable', 'string', 'max:120'],
            'incident_date' => [$complaint ? 'nullable' : 'prohibited', 'date', 'before_or_equal:today'],
            'incident_location' => [$complaint ? 'nullable' : 'prohibited', 'string', 'max:255'],
            'attachments' => [$complaint ? 'sometimes' : 'prohibited', 'array', 'max:3'],
            'attachments.*' => ['file', 'max:5120', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'message.required' => __('service-feedback.validation.message_required'),
            'position_service_id.uuid' => __('service-feedback.validation.service_required'),
            'position_service_id.exists' => __('service-feedback.service_not_provided'),
            'attachments.max' => __('service-feedback.validation.attachments_max'),
            'attachments.*.max' => __('service-feedback.validation.attachment_size'),
            'attachments.*.mimes' => __('service-feedback.validation.attachment_type'),
            'attachments.*.extensions' => __('service-feedback.validation.attachment_type'),
            'attachments.*.mimetypes' => __('service-feedback.validation.attachment_type'),
            'incident_date.before_or_equal' => __('service-feedback.validation.incident_date_future'),
        ];
    }
}
