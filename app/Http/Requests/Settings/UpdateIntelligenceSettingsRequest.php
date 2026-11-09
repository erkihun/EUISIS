<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\SystemSettings\SystemSettingsRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Intelligence settings group. The rules are the registry's own, so the
 * form, the request and the typed reader (IntelligenceSettings) cannot drift.
 * Detection limits are nullable: blank means "not configured", and a rule
 * without a limit counts nothing.
 */
class UpdateIntelligenceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-settings.manageIntelligence') ?? false;
    }

    public function rules(): array
    {
        return collect(SystemSettingsRegistry::group(SystemSettingsRegistry::GROUP_INTELLIGENCE))
            ->map(fn (array $definition): array => $definition['validation_rules'])
            ->all();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $label = app()->getLocale() === 'am' ? 'label_am' : 'label_en';

        return collect(SystemSettingsRegistry::group(SystemSettingsRegistry::GROUP_INTELLIGENCE))
            ->map(fn (array $definition): string => (string) ($definition[$label] ?? $definition['label_en']))
            ->all();
    }
}
