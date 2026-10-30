<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Communication\Domain\Enums\MemberRole;

/** Promote, demote or hand over ownership (authorization: ChangeMemberRoleAction). */
class ChangeMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['role' => ['required', Rule::in(MemberRole::values())]];
    }
}
