<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\ServiceMonitoring\Domain\Forms\TemplateContent;
use Modules\ServiceMonitoring\Domain\Models\FormTemplate;

/** A draft form template. Content is checked structurally; publishing adds the completeness rules. */
final class SaveFormTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('template');

        return $template instanceof FormTemplate
            ? $this->user()->can('update', $template)
            : $this->user()->can('create', FormTemplate::class);
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
        $creating = ! $this->route('template') instanceof FormTemplate;

        return [
            'code' => $creating
                ? ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_\-]+$/', Rule::unique('service_monitoring_form_templates', 'code')]
                : ['prohibited'],
            'name_am' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'organization_type_ids' => ['present', 'array', 'max:100'],
            'organization_type_ids.*' => ['uuid', 'distinct', Rule::exists('organization_types', 'id')->whereNull('deleted_at')],
            'content' => ['required', 'array'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('content')) {
                    return;
                }
                foreach (TemplateContent::from((array) $this->input('content'))->problems() as $path => $message) {
                    $validator->errors()->add($path, $message);
                }
            },
        ];
    }

    /** @return array<string, mixed> */
    public function templateAttributes(): array
    {
        $data = $this->validated();
        $data['organization_type_ids'] = array_values($data['organization_type_ids'] ?? []);

        return $data;
    }
}
