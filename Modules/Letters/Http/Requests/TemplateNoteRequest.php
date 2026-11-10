<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The change note of a published version. */
final class TemplateNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['change_note' => ['nullable', 'string', 'max:500']];
    }
}
