<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The shape of an autosave. Who may save, and whether the report can still
 * be edited, is decided by the use case; the narrative is sanitized there.
 */
final class SaveDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'mode' => ['sometimes', Rule::in(['structured', 'upload'])],
            'content' => ['sometimes', 'array', 'max:60'],
            // About 300 pages of text per section; a pasted image as data would exceed it and is not accepted anyway.
            'content.*' => ['nullable', 'string', 'max:600000'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_nil' => ['sometimes', 'boolean'],
            'nil_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'completeness_declared' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> only the keys that were sent */
    public function draft(): array
    {
        return $this->safe()->only(['mode', 'content', 'summary', 'is_nil', 'nil_reason', 'completeness_declared']);
    }
}
