<?php

declare(strict_types=1);

namespace App\Services\SystemSettings;

use App\Models\SystemSetting;

class PublicSettingsService
{
    /**
     * @return array<string, mixed>
     */
    public function shareableSettings(): array
    {
        $settings = [];
        $rows = SystemSetting::query()->get()->keyBy(fn (SystemSetting $setting) => $setting->group.'.'.$setting->key);

        foreach (SystemSettingsRegistry::definitions() as $group => $fields) {
            $settings[$group] = [];

            foreach ($fields as $key => $definition) {
                $row = $rows->get($group.'.'.$key);
                $settings[$group][$key] = $row?->typedValue() ?? $definition['default'] ?? null;
            }
        }

        $logoPath = $settings['general']['identity_system_logo'] ?? null;
        $faviconPath = $settings['general']['favicon'] ?? null;
        $sealPath = $settings['general']['seal'] ?? null;

        return [
            'app.name' => $settings['general']['application_name'] ?? 'Addis Ababa Employee Unified ID & Service Platform',
            'app.short_name' => $settings['general']['application_short_name'] ?? 'AA Employee ID',
            'general.organization_name' => $settings['general']['organization_name'] ?? 'Addis Ababa City Administration',
            'general.support_email' => $settings['general']['support_email'] ?? null,
            'general.support_phone' => $settings['general']['support_phone'] ?? null,
            'general.system_environment_label' => $settings['general']['system_environment_label'] ?? null,
            'general.help_center_url' => $settings['general']['help_center_url'] ?? null,
            'general.privacy_policy_url' => $settings['general']['privacy_policy_url'] ?? null,
            'general.terms_url' => $settings['general']['terms_url'] ?? null,
            'general.login_page_message_en' => $settings['general']['login_page_message_en'] ?? null,
            'general.login_page_message_am' => $settings['general']['login_page_message_am'] ?? null,
            'general.identity_system_logo_url' => $this->publicAssetUrl($logoPath),
            'general.favicon_url' => $this->publicAssetUrl($faviconPath),
            'general.seal_url' => $this->publicAssetUrl($sealPath),
            'localization.default_locale' => $settings['localization']['default_locale'] ?? 'en',
            'localization.fallback_locale' => $settings['localization']['fallback_locale'] ?? 'en',
            'localization.supported_locales' => $settings['localization']['supported_locales'] ?? ['en', 'am'],
            'localization.timezone' => $settings['localization']['timezone'] ?? 'Africa/Addis_Ababa',
            'localization.date_format' => $settings['localization']['date_format'] ?? 'Y-m-d',
            'localization.datetime_format' => $settings['localization']['datetime_format'] ?? 'Y-m-d H:i',
            'appearance.default_theme' => $settings['appearance']['default_theme'] ?? 'system',
            'appearance.primary_color' => $settings['appearance']['primary_color'] ?? '#2563EB',
            'appearance.secondary_color' => $settings['appearance']['secondary_color'] ?? '#1E40AF',
            'appearance.accent_color' => $settings['appearance']['accent_color'] ?? '#F97316',
            'appearance.table_density' => $settings['appearance']['table_density'] ?? 'comfortable',
            'appearance.button_style' => $settings['appearance']['button_style'] ?? 'rounded',
            'appearance.card_radius' => $settings['appearance']['card_radius'] ?? 'xl',
            'appearance.allow_user_theme_switching' => $settings['appearance']['allow_user_theme_switching'] ?? true,
            'appearance.sidebar_compact_default' => $settings['appearance']['sidebar_compact_default'] ?? false,
            'appearance.show_language_switcher' => $settings['appearance']['show_language_switcher'] ?? true,
            'appearance.enable_ui_animations' => $settings['appearance']['enable_ui_animations'] ?? true,
            'appearance.default_page_size' => $settings['appearance']['default_page_size'] ?? 25,
            'security.maintenance_banner_enabled' => $settings['security']['maintenance_banner_enabled'] ?? false,
            'security.maintenance_banner_message_en' => $settings['security']['maintenance_banner_message_en'] ?? null,
            'security.maintenance_banner_message_am' => $settings['security']['maintenance_banner_message_am'] ?? null,
            // ID Cards
            'id_cards.front_bg_from' => $settings['id_cards']['front_bg_from'] ?? '#1D4ED8',
            'id_cards.front_bg_to' => $settings['id_cards']['front_bg_to'] ?? '#1E3A8A',
            'id_cards.front_text_primary' => $settings['id_cards']['front_text_primary'] ?? '#FFFFFF',
            'id_cards.front_text_secondary' => $settings['id_cards']['front_text_secondary'] ?? '#BFDBFE',
            'id_cards.front_name_font_size' => $settings['id_cards']['front_name_font_size'] ?? 'sm',
            'id_cards.front_label_font_size' => $settings['id_cards']['front_label_font_size'] ?? 'xs',
            'id_cards.city_name_en' => $settings['id_cards']['city_name_en'] ?? 'Addis Ababa City Administration',
            'id_cards.city_name_am' => $settings['id_cards']['city_name_am'] ?? 'አዲስ አበባ ከተማ አስተዳደር',
            'id_cards.bureau_name_en' => $settings['id_cards']['bureau_name_en'] ?? 'Public Service & HRD Bureau',
            'id_cards.bureau_name_am' => $settings['id_cards']['bureau_name_am'] ?? 'የሲቪል ሰርቪስና ሰው ሃብት ልማት ቢሮ',
            'id_cards.show_organization_logo' => $settings['id_cards']['show_organization_logo'] ?? true,
            'id_cards.back_bg_from' => $settings['id_cards']['back_bg_from'] ?? '#1E293B',
            'id_cards.back_bg_to' => $settings['id_cards']['back_bg_to'] ?? '#0F172A',
            'id_cards.back_text_color' => $settings['id_cards']['back_text_color'] ?? '#94A3B8',
            'id_cards.return_address_en' => $settings['id_cards']['return_address_en'] ?? 'Addis Ababa City Administration, Public Service & HRD Bureau',
            'id_cards.return_address_am' => $settings['id_cards']['return_address_am'] ?? 'አዲስ አበባ ከተማ አስተዳደር፣ የሲቪል ሰርቪስና ሰው ሃብት ልማት ቢሮ',
            'id_cards.show_magnetic_stripe' => $settings['id_cards']['show_magnetic_stripe'] ?? true,
            'id_cards.qr_size' => $settings['id_cards']['qr_size'] ?? '100',
            'id_cards.card_padding' => $settings['id_cards']['card_padding'] ?? 'normal',
        ];
    }

    private function publicAssetUrl(mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return '/storage/'.ltrim($path, '/');
    }
}
