<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFieldWorkGpsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-settings.manageFieldWorkGps') ?? false;
    }

    public function rules(): array
    {
        return [
            'require_check_in' => ['required', 'boolean'],
            'require_check_out' => ['required', 'boolean'],
            'max_accuracy_meters' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'low_accuracy_action' => ['required', 'in:not_configured,record_only,require_review,block'],
            'outside_geofence_action' => ['required', 'in:not_configured,record_only,require_review,block'],
            'location_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'offline_capture_policy' => ['required', 'in:not_configured,disallow,allow_with_review'],
            'team_capture_policy' => ['required', 'in:not_configured,individual_only'],
        ];
    }
}
