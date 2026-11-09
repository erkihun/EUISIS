<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;

/** The Section II figures (non-negative whole numbers), the narrative and the override reasons (D18). */
final class SaveSection2Request extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('submission'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'values' => ['required', 'array'],
            ...collect(FormDefinition::section2Keys())->mapWithKeys(fn (string $f): array => ["values.{$f}" => ['nullable', 'integer', 'min:0', 'max:10000000']])->all(),
            'narrative' => ['nullable', 'string', 'max:10000'],
            'override_reasons' => ['sometimes', 'array'],
            'override_reasons.*' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
