<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceMonitoring\Domain\Enums\ServiceLevel;
use Modules\ServiceMonitoring\Domain\Enums\TechnologyType;

/** A Section III row (D20). The catalog, parent and fiscal-year rules are checked in SaveSection3Entry. */
final class SaveDigitalServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('submission'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'level' => ['required', Rule::enum(ServiceLevel::class)],
            'service_id' => ['nullable', 'uuid'],
            'parent_entry_id' => ['nullable', 'required_if:level,sub', 'uuid'],
            'service_name_am' => ['nullable', 'required_without:service_id', 'string', 'max:255'],
            'service_name_en' => ['nullable', 'string', 'max:255'],
            'technology_type' => ['required', Rule::enum(TechnologyType::class)],
            'technology_other' => ['nullable', 'required_if:technology_type,other', 'string', 'max:160'],
            'system_name' => ['required', 'string', 'max:255'],
            'system_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'go_live_on' => ['required', 'date_format:Y-m-d'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
