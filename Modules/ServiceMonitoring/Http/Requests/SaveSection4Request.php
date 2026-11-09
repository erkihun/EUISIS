<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The service totals and the Section IV counts. The sum rules are checked before submission (D21). */
final class SaveSection4Request extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('submission'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'main_total' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'sub_total' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'main_above' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'main_at' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'main_below' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'sub_above' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'sub_at' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'sub_below' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'below_reasons' => ['nullable', 'string', 'max:5000'],
            'actions_taken' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
