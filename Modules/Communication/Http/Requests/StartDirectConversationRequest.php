<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Start (or reopen) the direct conversation with one person. Authorization is the policy's, checked in the action. */
class StartDirectConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
