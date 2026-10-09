<?php

declare(strict_types=1);

namespace App\Http\Requests\DailyActivity;

use App\Services\SystemSettings\SystemSettingsRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saves the `daily_activity` settings group.
 *
 * Rules come from the registry so every registered field has one (which
 * SettingsIntegrationTest asserts); a field without a rule would be dropped
 * by validated() and silently never saved.
 */
class UpdateDailyActivitySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('daily_activity_settings.update') ?? false;
    }

    public function rules(): array
    {
        $rules = [];

        foreach (SystemSettingsRegistry::group(SystemSettingsRegistry::GROUP_DAILY_ACTIVITY) as $key => $definition) {
            $rules[$key] = $definition['validation_rules'] ?? ['nullable'];
        }

        $rules['work_week_days.*'] = ['required', 'in:1,2,3,4,5,6,7'];

        return $rules;
    }
}
