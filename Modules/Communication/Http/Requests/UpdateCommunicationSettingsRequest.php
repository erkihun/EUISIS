<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Requests;

use App\Services\SystemSettings\SystemSettingsRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Communication settings group. Rules come from the registry (one source
 * for what each setting accepts); blank retention means "keep everything".
 */
class UpdateCommunicationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('communication.settings.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [];
        foreach (SystemSettingsRegistry::group(SystemSettingsRegistry::GROUP_COMMUNICATION) as $key => $field) {
            $fieldRules = $field['validation_rules'] ?? ['nullable'];
            // A setting not sent is left as it is.
            $rules[$key] = ['sometimes', ...array_values(array_filter($fieldRules, fn ($r) => $r !== 'required'))];
            if ($field['type'] === 'multiselect') {
                $rules[$key.'.*'] = ['string', 'max:10', 'alpha_num'];
            }
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $locale = app()->getLocale() === 'am' ? 'am' : 'en';

        return collect(SystemSettingsRegistry::group(SystemSettingsRegistry::GROUP_COMMUNICATION))
            ->mapWithKeys(fn (array $field, string $key) => [$key => (string) ($field['label_'.$locale] ?? $key)])->all();
    }
}
