<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ServiceMonitoring\Domain\Models\MonitoringSetting;

/** The module settings: declaration text, stamped-copy rule, driver occupations. */
final class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', MonitoringSetting::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'require_stamped_copy' => ['required', 'boolean'],
            'declaration_text_en' => ['required', 'string', 'min:10', 'max:2000'],
            'declaration_text_am' => ['required', 'string', 'min:10', 'max:2000'],
            'owner_organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'driver_occupation_ids' => ['present', 'array', 'max:50'],
            'driver_occupation_ids.*' => ['uuid', 'exists:occupations,id'],
        ];
    }
}
