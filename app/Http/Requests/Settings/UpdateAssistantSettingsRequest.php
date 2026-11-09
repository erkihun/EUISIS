<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\SystemSettings\SystemSettingsRegistry;
use Illuminate\Foundation\Http\FormRequest;

/** The Assistant settings group; rules come from the registry so form, request and reader cannot drift. */
class UpdateAssistantSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-settings.manageIntelligence') ?? false;
    }

    public function rules(): array
    {
        return collect(SystemSettingsRegistry::group(SystemSettingsRegistry::GROUP_ASSISTANT))
            ->map(fn (array $definition): array => $definition['validation_rules'])
            ->all();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $label = app()->getLocale() === 'am' ? 'label_am' : 'label_en';

        return collect(SystemSettingsRegistry::group(SystemSettingsRegistry::GROUP_ASSISTANT))
            ->map(fn (array $definition): string => (string) ($definition[$label] ?? $definition['label_en']))
            ->all();
    }
}
