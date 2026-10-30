<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** An edited message body. Authorization is the policy's, checked in the action. */
class UpdateMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['present', 'nullable', 'string', 'max:10000'],
        ];
    }
}
