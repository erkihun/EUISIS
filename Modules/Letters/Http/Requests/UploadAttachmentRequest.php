<?php

declare(strict_types=1);

namespace Modules\Letters\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A scanned original or an annex. The content is sniffed by LetterAttachmentStore. */
final class UploadAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:15360'],
            'kind' => ['nullable', Rule::in(['scan', 'annex'])],
        ];
    }
}
