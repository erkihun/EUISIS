<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceMonitoring;

use App\Models\ServiceMonitoringForm;
use App\Support\ServiceMonitoring\FormSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Saves a draft form. The schema is checked structurally; publishing adds the completeness rules. */
class SaveServiceMonitoringFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        $form = $this->route('form');

        return $form instanceof ServiceMonitoringForm
            ? $this->user()->can('update', $form)
            : $this->user()->can('create', ServiceMonitoringForm::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $creating = ! $this->route('form') instanceof ServiceMonitoringForm;

        return [
            'code' => $creating
                ? ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_\-]+$/', Rule::unique('service_monitoring_forms', 'code')]
                : ['prohibited'],
            'name_am' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'organization_type_ids' => ['present', 'array', 'max:100'],
            'organization_type_ids.*' => ['uuid', 'distinct', Rule::exists('organization_types', 'id')->whereNull('deleted_at')],
            'schema' => ['required', 'array'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('schema')) {
                    return;
                }
                foreach (FormSchema::from((array) $this->input('schema'))->problems() as $path => $message) {
                    $validator->errors()->add($path, $message);
                }
            },
        ];
    }

    /** @return array<string, mixed> */
    public function formAttributes(): array
    {
        $data = $this->validated();
        $data['organization_type_ids'] = array_values($data['organization_type_ids'] ?? []);
        $data['schema'] = ['sections' => array_values((array) ($data['schema']['sections'] ?? []))];

        return $data;
    }
}
