<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Approves or rejects a correction request. */
final class DecideCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('decideCorrection', $this->route('correction')->submission);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'approve' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
