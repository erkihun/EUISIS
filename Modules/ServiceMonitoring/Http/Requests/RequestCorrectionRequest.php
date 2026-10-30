<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Asks to correct an accepted report (D9). */
final class RequestCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('requestCorrection', $this->route('submission'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
        ];
    }
}
