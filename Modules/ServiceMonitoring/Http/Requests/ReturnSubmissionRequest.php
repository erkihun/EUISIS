<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Returns a report with a reason and optional line comments. */
final class ReturnSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('review', $this->route('submission'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'comments' => ['sometimes', 'array', 'max:50'],
            'comments.*.section' => ['required', 'integer', 'between:1,4'],
            'comments.*.line_reference' => ['nullable', 'string', 'max:80'],
            'comments.*.body' => ['required', 'string', 'min:2', 'max:2000'],
        ];
    }
}
