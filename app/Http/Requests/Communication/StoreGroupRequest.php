<?php

declare(strict_types=1);

namespace App\Http\Requests\Communication;

use App\Services\Communication\MemberEligibility;
use Illuminate\Foundation\Http\FormRequest;

/** A new group: a name and at least one other person (authorization: CreateGroupAction). */
class StoreGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'member_ids' => ['required', 'array', 'min:1', 'max:'.(MemberEligibility::maxGroupMembers() - 1)],
            'member_ids.*' => ['integer', 'distinct'],
        ];
    }
}
