<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Communication\Domain\Enums\ReportCategory;

/** A report to the moderators (authorization: ReportContentAction). */
class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(ReportCategory::values())],
            'details' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
