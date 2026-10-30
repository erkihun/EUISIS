<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Http\Requests;

use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Cafeteria\Domain\Models\CafeteriaReportRun;

class GenerateCafeteriaReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('generate', CafeteriaReportRun::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'report_type' => ['required', 'in:daily,monthly'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'organization_id' => [app(OrganizationScopeService::class)->isUnrestricted($this->user()) ? 'nullable' : 'required', 'uuid', 'exists:organizations,id'],
        ];
    }
}
