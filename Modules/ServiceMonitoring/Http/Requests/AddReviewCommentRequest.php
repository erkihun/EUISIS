<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A reviewer's comment against a section or line. */
final class AddReviewCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('comment', $this->route('submission'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'section' => ['required', 'integer', 'between:1,4'],
            'line_reference' => ['nullable', 'string', 'max:80'],
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ];
    }
}
