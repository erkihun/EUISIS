<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Proof of delivery for a dispatched letter. */
final class AcknowledgeReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'acknowledged_on' => ['nullable', 'date'],
            'acknowledgement_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
