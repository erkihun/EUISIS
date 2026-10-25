<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The `settings` and `branding` props of every page, in the EUISIS shape
 * (EUISIS PublicSettingsService::shareableSettings and BrandingService), so
 * the copied screens read them unchanged. Keys the Core has come from Core
 * settings; the others keep the EUISIS defaults.
 */
final class SharedSettings
{
    /** Shared key => Core setting key. */
    private const FROM_CORE = [
        'app.name' => 'general.application_name',
        'app.name_am' => 'general.application_name_am',
        'app.short_name' => 'general.application_short_name',
        'app.official_name_en' => 'general.official_name_en',
        'app.official_name_am' => 'general.official_name_am',
        'general.organization_name' => 'general.organization_name',
        'general.system_environment_label' => 'general.system_environment_label',
        'general.login_page_message_en' => 'general.login_page_message_en',
        'general.login_page_message_am' => 'general.login_page_message_am',
        'localization.default_locale' => 'localization.default_locale',
        'appearance.default_theme' => 'appearance.default_theme',
        'appearance.primary_color' => 'appearance.primary_color',
        'appearance.secondary_color' => 'appearance.secondary_color',
        'appearance.accent_color' => 'appearance.accent_color',
        'appearance.sidebar_color' => 'appearance.sidebar_color',
        'appearance.table_density' => 'appearance.table_density',
        'appearance.button_style' => 'appearance.button_style',
        'appearance.card_radius' => 'appearance.card_radius',
        'appearance.allow_user_theme_switching' => 'appearance.allow_user_theme_switching',
        'appearance.sidebar_compact_default' => 'appearance.sidebar_compact_default',
        'appearance.logo_position' => 'appearance.logo_position',
        'appearance.show_breadcrumbs' => 'appearance.show_breadcrumbs',
        'appearance.sticky_table_headers' => 'appearance.sticky_table_headers',
        'appearance.show_language_switcher' => 'appearance.show_language_switcher',
        'appearance.enable_ui_animations' => 'appearance.enable_ui_animations',
        'appearance.default_page_size' => 'appearance.default_page_size',
        'security.maintenance_banner_enabled' => 'security.maintenance_banner_enabled',
        'security.maintenance_banner_message_en' => 'security.maintenance_banner_message_en',
        'security.maintenance_banner_message_am' => 'security.maintenance_banner_message_am',
        'id_cards.city_name_en' => 'id_cards.city_name_en',
        'id_cards.city_name_am' => 'id_cards.city_name_am',
        'id_cards.bureau_name_en' => 'id_cards.bureau_name_en',
        'id_cards.bureau_name_am' => 'id_cards.bureau_name_am',
    ];

    /** The EUISIS defaults for what the Core does not configure. */
    private const DEFAULTS = [
        'general.support_email' => null,
        'general.support_phone' => null,
        'general.default_dashboard_route' => 'core.dashboard',
        'general.help_center_url' => null,
        'general.privacy_policy_url' => null,
        'general.terms_url' => null,
        'general.identity_system_logo_url' => null,
        'general.login_card_photo_urls' => [],
        'general.favicon_url' => null,
        'general.seal_url' => null,
        'localization.fallback_locale' => 'en',
        'localization.supported_locales' => ['en', 'am'],
        'localization.timezone' => 'Africa/Addis_Ababa',
        'localization.date_format' => 'Y-m-d',
        'localization.datetime_format' => 'Y-m-d H:i',
        'localization.first_day_of_week' => '1',
        'localization.number_format' => '1,234.56',
        'localization.organization_name_display' => 'english',
        'localization.employee_name_display' => 'full_name',
        'localization.calendar_system_mode' => 'locale_based',
        'appearance.dashboard_layout' => 'executive',
        'appearance.dashboard_refresh_seconds' => 60,
        'public_site.enabled' => false,
        'id_cards.template' => 'classic',
        'id_cards.front_bg_from' => '#1D4ED8',
        'id_cards.front_bg_to' => '#1E3A8A',
        'id_cards.front_text_primary' => '#FFFFFF',
        'id_cards.front_text_secondary' => '#BFDBFE',
        'id_cards.front_name_font_size' => 'sm',
        'id_cards.front_label_font_size' => 'xs',
        'id_cards.show_organization_logo' => true,
        'id_cards.show_photo' => true,
        'id_cards.show_full_name_en' => true,
        'id_cards.show_full_name_am' => true,
        'id_cards.show_employee_number' => true,
        'id_cards.show_card_number' => true,
        'id_cards.show_organization' => true,
        'id_cards.show_organization_unit' => true,
        'id_cards.show_position' => true,
        'id_cards.show_job_grade' => true,
        'id_cards.show_employment_status' => true,
        'id_cards.show_issue_date' => true,
        'id_cards.show_expiry_date' => true,
        'id_cards.show_signature' => false,
        'id_cards.show_qr' => true,
        'id_cards.show_return_notice' => true,
        'id_cards.show_emergency_contact' => true,
        'id_cards.verification_url' => null,
        'id_cards.back_bg_from' => '#1E293B',
        'id_cards.back_bg_to' => '#0F172A',
        'id_cards.back_text_color' => '#94A3B8',
        'id_cards.return_address_en' => 'Addis Ababa City Administration, Public Service & HRD Bureau',
        'id_cards.return_address_am' => 'አዲስ አበባ ከተማ አስተዳደር፣ የሲቪል ሰርቪስና ሰው ሀብት ልማት ቢሮ',
        'id_cards.back_notice_am' => 'ካርዱ የሚወዳደርበት ቦታ ካገኙ ወደ ቅርብ ባለስልጣን ይመልሱ።',
        'id_cards.back_notice_en' => 'If found, please return to the issuing bureau.',
        'id_cards.show_magnetic_stripe' => true,
        'id_cards.qr_size' => '100',
        'id_cards.card_padding' => 'normal',
    ];

    public function __construct(private readonly CoreSettings $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $values = self::DEFAULTS;
        foreach (self::FROM_CORE as $shared => $core) {
            $values[$shared] = $this->settings->get($core);
        }

        return $values;
    }

    /** @return array{brandName: string, brandNameEn: string, brandNameAm: string, shortName: string, officialNameEn: string, officialNameAm: string, logoUrl: null, faviconUrl: null} */
    public function branding(): array
    {
        $en = (string) $this->settings->get('general.application_name');
        $am = (string) $this->settings->get('general.application_name_am');

        return [
            'brandName' => app()->getLocale() === 'am' && $am !== '' ? $am : $en,
            'brandNameEn' => $en,
            'brandNameAm' => $am,
            'shortName' => (string) $this->settings->get('general.application_short_name'),
            'officialNameEn' => (string) $this->settings->get('general.official_name_en'),
            'officialNameAm' => (string) $this->settings->get('general.official_name_am'),
            'logoUrl' => null,
            'faviconUrl' => null,
        ];
    }
}
