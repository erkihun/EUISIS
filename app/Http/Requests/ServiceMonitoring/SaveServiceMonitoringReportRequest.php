<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceMonitoring;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Answers are validated against the report's form version by
 * ServiceMonitoringSubmissionService; this request bounds the envelope.
 */
class SaveServiceMonitoringReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('submission'));
    }

    protected function prepareForValidation(): void
    {
        // Multipart posts carry the answers as one JSON field.
        if (is_string($this->input('data'))) {
            $decoded = json_decode($this->input('data'), true);
            $this->merge(['data' => is_array($decoded) ? $decoded : null]);
        }
    }

    public function rules(): array
    {
        return [
            'data' => ['present', 'array'],
            'submit' => ['required', 'boolean'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
