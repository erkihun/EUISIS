<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/** Moves a period's dates. The dates must keep their order (D5). */
final class UpdatePeriodScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', ReportingPeriod::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'opens_on' => ['required', 'date_format:Y-m-d'],
            'submission_due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:opens_on'],
            'subcity_review_due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:submission_due_on'],
            'city_review_due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:subcity_review_due_on'],
        ];
    }
}
