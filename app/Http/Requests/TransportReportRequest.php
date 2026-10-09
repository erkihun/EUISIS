<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransportReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('transport-reports.view') ?? false)
            || (auth('provider')->user()?->canUseServicePermission('provider.transport.reports.view') ?? false);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }
}
