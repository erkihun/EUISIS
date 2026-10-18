<?php

declare(strict_types=1);

namespace App\Http\Requests\Communication;

use App\Enums\Communication\ChannelPostingPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Rename or describe a group or channel (authorization: UpdateConversationAction). */
class UpdateConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'name_am' => ['sometimes', 'nullable', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'posting_policy' => ['sometimes', Rule::in(ChannelPostingPolicy::values())],
        ];
    }
}
