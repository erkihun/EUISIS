<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CafeteriaReportRun;
use Illuminate\Foundation\Http\FormRequest;

class GenerateCafeteriaReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('generate', CafeteriaReportRun::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'report_type' => ['required', 'in:daily,weekly,monthly'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
        ];
    }
}
