<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Any action that needs only a reason: reopening, revoking, recording a non-submission, rejecting a digital service. The controller authorizes against the record. */
final class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
