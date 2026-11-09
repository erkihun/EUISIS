<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationalChange;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Applying an approved change.
 *
 * The only field accepted is an implementation note. Quantity, grade, unit,
 * parent, title and effective date are NOT accepted from the implementer:
 * they come from the frozen approved payload. Anything else posted here is
 * ignored by design.
 */
class ImplementOrganizationalChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('implement', $this->route('organizationalChangeRequest')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
