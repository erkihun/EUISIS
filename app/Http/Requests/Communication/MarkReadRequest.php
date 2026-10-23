<?php

declare(strict_types=1);

namespace App\Http\Requests\Communication;

use Illuminate\Foundation\Http\FormRequest;

/** Read up to a message (or the latest when omitted). Authorization is the policy's, checked in the action. */
class MarkReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message_id' => ['nullable', 'uuid'],
        ];
    }
}
