<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/** Creates a fiscal year: the coordinator only. */
final class StoreFiscalYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', ReportingPeriod::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ec_year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
