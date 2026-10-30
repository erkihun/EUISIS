<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceMonitoring;

use App\Models\ServiceMonitoringForm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DuplicateServiceMonitoringFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ServiceMonitoringForm::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_\-]+$/', Rule::unique('service_monitoring_forms', 'code')],
            'name_am' => ['required', 'string', 'max:255'],
        ];
    }
}
