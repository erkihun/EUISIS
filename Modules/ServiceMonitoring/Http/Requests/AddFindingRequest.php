<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceMonitoring\Domain\Enums\FindingSeverity;

/** A finding of a visit. */
final class AddFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('visit'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'section' => ['nullable', 'integer', 'between:1,4'],
            'body' => ['required', 'string', 'min:5', 'max:5000'],
            'severity' => ['required', Rule::enum(FindingSeverity::class)],
        ];
    }
}
