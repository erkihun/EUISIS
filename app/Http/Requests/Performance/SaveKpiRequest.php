<?php

declare(strict_types=1);

namespace App\Http\Requests\Performance;

use App\Enums\Performance\KpiAggregation;
use App\Enums\Performance\KpiDataSource;
use App\Enums\Performance\KpiDirection;
use App\Enums\Performance\KpiFrequency;
use App\Enums\Performance\KpiMeasurementType;
use App\Services\Performance\SystemKpiSourceRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shape validation only; authorization and business rules live in the EPMS services. */
class SaveKpiRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authority (scope, manager, workflow state) is enforced by the service.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('kpis', 'code')->ignore($this->route('kpi'))],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'description_am' => ['nullable', 'string', 'max:5000'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'measurement_type' => ['required', Rule::enum(KpiMeasurementType::class)],
            'unit_of_measure' => ['nullable', 'string', 'max:64'],
            'direction' => ['required', Rule::enum(KpiDirection::class)],
            'aggregation_method' => ['required', Rule::enum(KpiAggregation::class)],
            'data_source_type' => ['required', Rule::enum(KpiDataSource::class)],
            'system_source_key' => ['nullable', 'required_if:data_source_type,SYSTEM_TRANSACTION', Rule::in(array_keys(SystemKpiSourceRegistry::sources()))],
            'calculation_formula' => ['nullable', 'string', 'max:2000'],
            'baseline' => ['nullable', 'numeric'],
            'frequency' => ['required', Rule::enum(KpiFrequency::class)],
            'allow_overachievement' => ['boolean'],
            'achievement_cap' => ['nullable', 'numeric', 'min:100', 'max:200'],
            'target_tolerance' => ['nullable', 'numeric', 'min:0'],
            'zero_score_deviation' => ['nullable', 'numeric', 'gt:0'],
            'milestones' => ['nullable', 'array', 'required_if:direction,MILESTONE'],
            'milestones.*.key' => ['required', 'string', 'max:64', 'alpha_dash'],
            'milestones.*.label_en' => ['required', 'string', 'max:120'],
            'milestones.*.label_am' => ['nullable', 'string', 'max:120'],
            'milestones.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'milestones.*.requires_verification' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
