<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Attests and submits a report: the declaration must be ticked (D11). */
final class SubmitSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('submit', $this->route('submission'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'declaration' => ['accepted'],
        ];
    }
}
