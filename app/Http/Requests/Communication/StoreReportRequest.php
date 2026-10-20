<?php

declare(strict_types=1);

namespace App\Http\Requests\Communication;

use App\Enums\Communication\ReportCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
