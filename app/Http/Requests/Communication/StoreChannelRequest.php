<?php

declare(strict_types=1);

namespace App\Http\Requests\Communication;

use App\Enums\Communication\ChannelPostingPolicy;
use App\Enums\Communication\ChannelType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A new channel (authorization and scope rules: CreateChannelAction). */
class StoreChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'name_am' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', Rule::in(ChannelType::values())],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'organization_unit_id' => ['nullable', 'uuid', 'exists:organization_units,id'],
            'include_descendants' => ['boolean'],
            'auto_membership' => ['boolean'],
            'remove_on_transfer' => ['boolean'],
            'posting_policy' => ['nullable', Rule::in(ChannelPostingPolicy::values())],
        ];
    }
}
