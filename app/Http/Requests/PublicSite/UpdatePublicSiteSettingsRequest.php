<?php

declare(strict_types=1);

namespace App\Http\Requests\PublicSite;

use App\Services\SystemSettings\SystemSettingsRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saves the `public_site` settings group.
 *
 * Rules are taken from the registry so the two can never drift: every
 * registered field has a rule, which is what SettingsIntegrationTest asserts —
 * a field without one would be silently dropped by `validated()`.
 */
class UpdatePublicSiteSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('public_site_settings.update') ?? false;
    }

    public function rules(): array
    {
        $rules = [];

        foreach (SystemSettingsRegistry::group(SystemSettingsRegistry::GROUP_PUBLIC_SITE) as $key => $definition) {
            $rules[$key] = $definition['validation_rules'] ?? ['nullable'];
        }

        return $rules;
    }
}
