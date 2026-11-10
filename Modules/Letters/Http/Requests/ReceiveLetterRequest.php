<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Receiving a letter dispatched to the institution. */
final class ReceiveLetterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'received_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date'],
            'physical_location' => ['nullable', 'string', 'max:120'],
        ];
    }
}
