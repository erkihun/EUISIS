<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Sets or clears a section's "የለም" flag (D10). */
final class SetNothingToReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('submission'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'section' => ['required', 'integer', 'between:1,4'],
            'nothing' => ['required', 'boolean'],
        ];
    }
}
