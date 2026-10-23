<?php

declare(strict_types=1);

namespace App\Http\Requests\Communication;

use Illuminate\Foundation\Http\FormRequest;

/** A new message: text, files, a reply reference and an idempotency key. Authorization is the policy's, checked in the action. */
class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:10000'],
            'parent_message_id' => ['nullable', 'uuid'],
            'attachment_ids' => ['nullable', 'array', 'max:10'],
            'attachment_ids.*' => ['uuid'],
            'client_message_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
