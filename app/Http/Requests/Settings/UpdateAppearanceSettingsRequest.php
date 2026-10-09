<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppearanceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('system-settings.manageAppearance') ?? false;
    }

    public function rules(): array
    {
        $hex = ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        return [
            'default_theme' => ['required', Rule::in(['light', 'dark', 'system'])],
            'primary_color' => $hex,
            'secondary_color' => $hex,
            'accent_color' => $hex,
            /*
             * Every appearance key must be listed here, not only validated:
             * the controller persists `$request->validated()`, which drops any
             * field without a rule. A key registered in SystemSettingsRegistry
             * but missing from this list renders in the form, submits, and is
             * then silently discarded — the setting simply never saves.
             */
            'sidebar_color' => $hex,
            'table_density' => ['required', Rule::in(['compact', 'comfortable', 'spacious'])],
            'button_style' => ['required', Rule::in(['rounded', 'soft', 'square'])],
            'card_radius' => ['required', Rule::in(['sm', 'md', 'lg', 'xl', '2xl'])],
            'allow_user_theme_switching' => ['required', 'boolean'],
            'sidebar_compact_default' => ['required', 'boolean'],
            'show_breadcrumbs' => ['required', 'boolean'],
            'show_language_switcher' => ['required', 'boolean'],
            'dashboard_layout' => ['required', Rule::in(['executive', 'compact'])],
            'dashboard_refresh_seconds' => ['required', 'integer', 'min:15', 'max:3600'],
            'enable_ui_animations' => ['required', 'boolean'],
            'sticky_table_headers' => ['required', 'boolean'],
            'default_page_size' => ['required', 'integer', 'min:10', 'max:100'],
            'logo_position' => ['required', Rule::in(['start', 'center'])],
        ];
    }
}
