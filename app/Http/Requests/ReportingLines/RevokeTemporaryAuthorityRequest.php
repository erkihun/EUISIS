<?php

declare(strict_types=1);

namespace App\Http\Requests\ReportingLines;

use Illuminate\Foundation\Http\FormRequest;

/** Revoking an acting appointment or a delegation always says why. The controller checks the policy on the record. */
class RevokeTemporaryAuthorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
