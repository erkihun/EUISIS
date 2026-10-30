<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceMonitoring;

use Illuminate\Foundation\Http\FormRequest;

class ReviewServiceMonitoringReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('review', $this->route('submission'));
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:accept,return'],
            'note' => ['nullable', 'required_if:decision,return', 'string', 'max:2000'],
        ];
    }
}
