<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceMonitoring\Domain\Models\FormTemplate;

final class DuplicateFormTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FormTemplate::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_\-]+$/', Rule::unique('service_monitoring_form_templates', 'code')],
            'name_am' => ['required', 'string', 'max:255'],
        ];
    }
}
