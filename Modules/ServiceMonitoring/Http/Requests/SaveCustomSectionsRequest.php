<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Answers to the form template's additional sections; SaveCustomSections validates them against the template. */
final class SaveCustomSectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('submission'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'custom' => ['present', 'array'],
        ];
    }
}
