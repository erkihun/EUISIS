<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceMonitoring;

use App\Enums\ServiceMonitoring\PeriodStatus;
use App\Enums\ServiceMonitoring\PeriodType;
use App\Models\ServiceMonitoringPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveServiceMonitoringPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        $period = $this->route('period');

        return $period instanceof ServiceMonitoringPeriod
            ? $this->user()->can('update', $period)
            : $this->user()->can('create', ServiceMonitoringPeriod::class);
    }

    public function rules(): array
    {
        $period = $this->route('period');

        return [
            'fiscal_year' => ['required', 'integer', 'min:1990', 'max:2100'],
            'period_type' => [
                'required', Rule::enum(PeriodType::class),
                Rule::unique('service_monitoring_periods', 'period_type')
                    ->where('fiscal_year', (int) $this->input('fiscal_year'))
                    ->ignore($period instanceof ServiceMonitoringPeriod ? $period->id : null),
            ],
            'name_am' => ['nullable', 'string', 'max:160'],
            'name_en' => ['nullable', 'string', 'max:160'],
            'due_date' => ['nullable', 'date'],
            'status' => ['required', Rule::enum(PeriodStatus::class)],
        ];
    }

    public function messages(): array
    {
        return ['period_type.unique' => __('service-monitoring.errors.period_exists')];
    }

    /** @return array<string, mixed> */
    public function periodAttributes(): array
    {
        $data = $this->validated();
        $type = PeriodType::from($data['period_type']);
        $year = (int) $data['fiscal_year'];
        $data['name_am'] = filled($data['name_am'] ?? null) ? $data['name_am'] : $type->defaultNameAm($year);
        $data['name_en'] = filled($data['name_en'] ?? null) ? $data['name_en'] : $type->defaultNameEn($year);

        return $data;
    }
}
