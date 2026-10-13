<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPublicSubmitter;
use App\Rules\SafeUploadName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePublicServiceEntryRequest extends FormRequest
{
    use ValidatesPublicSubmitter;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $complaint = $this->route('type') === 'complaint';
        $employeeTarget = $this->routeIs('service-feedback.*');

        return [
            'form_token' => ['nullable', 'string', 'size:32'],
            'category_id' => ['nullable', 'uuid'],
            'position_service_id' => $employeeTarget
                ? ['nullable', 'uuid', Rule::exists('position_services', 'id')->whereNull('deleted_at')]
                : ['prohibited'],
            'message' => ['required', 'string', 'max:5000'],
            ...$this->submitterRules(),
            // A suggestion carries no incident. The browser form still sends both keys empty, which
            // `prohibited` accepts; the date and string rules must not run on them, or every
            // suggestion failed with "not a valid date" on a field the suggestion form never shows.
            'incident_date' => $complaint ? ['nullable', 'date', 'before_or_equal:today'] : ['prohibited'],
            'incident_location' => $complaint ? ['nullable', 'string', 'max:255'] : ['prohibited'],
            'attachments' => $this->attachmentsRule($complaint),
            'attachments.*' => ['file', new SafeUploadName, 'max:5120', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->submitterMessages() + [
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
