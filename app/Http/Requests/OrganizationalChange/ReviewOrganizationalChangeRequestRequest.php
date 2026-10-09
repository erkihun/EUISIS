<?php

declare(strict_types=1);

namespace App\Http\Requests\OrganizationalChange;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A reviewer decision. Correction and rejection both require a comment: the
 * requester must always be told what to change or why it was refused.
 *
 * Note there is no field here for editing the proposal. A reviewer cannot
 * alter what was asked for and approve different values; changing the ask
 * means requesting a correction.
 */
class ReviewOrganizationalChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller authorizes per action against the specific policy
        // method, which depends on which decision is being taken.
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
