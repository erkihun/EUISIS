<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ServiceMonitoring\Domain\Models\Participant;

/** Registers the participants the structure suggests. */
final class ApplySuggestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Participant::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'active_from' => ['required', 'date_format:Y-m-d'],
            'organization_ids' => ['required', 'array', 'min:1'],
            'organization_ids.*' => ['uuid'],
        ];
    }
}
