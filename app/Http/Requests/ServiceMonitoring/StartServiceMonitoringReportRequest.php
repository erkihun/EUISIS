<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceMonitoring;

use App\Models\ServiceMonitoringSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartServiceMonitoringReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ServiceMonitoringSubmission::class);
    }

    public function rules(): array
    {
        return [
            'form_id' => ['required', 'uuid', Rule::exists('service_monitoring_forms', 'id')],
            'period_id' => ['required', 'uuid', Rule::exists('service_monitoring_periods', 'id')],
            'organization_id' => ['required', 'uuid', Rule::exists('organizations', 'id')->whereNull('deleted_at')],
        ];
    }
}
