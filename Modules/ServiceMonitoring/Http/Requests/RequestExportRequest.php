<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceMonitoring\Domain\Enums\ReportRange;

/** An official-form export (D25). Scope access is checked in the controller. */
final class RequestExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('service_monitoring.reports.export');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope_type' => ['required', Rule::in(['submission', 'sub_city', 'city'])],
            'scope_id' => ['nullable', 'required_unless:scope_type,city', 'uuid'],
            'fiscal_year_id' => ['required', 'uuid', 'exists:service_monitoring_fiscal_years,id'],
            'quarter' => ['required', 'integer', 'between:1,4'],
            'range' => ['required', Rule::enum(ReportRange::class)],
            'format' => ['required', Rule::in(['xlsx', 'pdf'])],
            'named' => ['sometimes', 'boolean'],
        ];
    }
}
