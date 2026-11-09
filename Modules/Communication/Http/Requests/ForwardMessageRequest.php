<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Up to five target conversations. Authorization is the policy's, checked in the action. */
class ForwardMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'conversation_ids' => ['required', 'array', 'min:1', 'max:5'],
            'conversation_ids.*' => ['uuid'],
        ];
    }
}
