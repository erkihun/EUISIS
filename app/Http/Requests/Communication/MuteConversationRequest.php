<?php

declare(strict_types=1);

namespace App\Http\Requests\Communication;

use Illuminate\Foundation\Http\FormRequest;

/** Mute for some minutes, or until unmuted (null minutes with mute true). Authorization is the policy's, checked in the action. */
class MuteConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mute' => ['required', 'boolean'],
            'minutes' => ['nullable', 'integer', 'min:5', 'max:525600'],
        ];
    }
}
