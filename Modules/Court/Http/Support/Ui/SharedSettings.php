<?php

declare(strict_types=1);

namespace Modules\Court\Http\Support\Ui;

use Modules\Court\Domain\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;

/**
 * The `settings` and `branding` props every page gets, in the EUISIS shape
 * (EUISIS PublicSettingsService::shareableSettings and BrandingService) so
 * the screens copied from EUISIS read them unchanged. Values court-ms
 * configures (System Settings) come from its system_settings row; the rest
 * keep the EUISIS defaults, as in the EUISIS Core platform.
 */
final class SharedSettings
{
    /** The EUISIS defaults for what court-ms does not configure. */
    private const DEFAULTS = [
        'app.name' => 'Court Case Management',
        'app.name_am' => 'የፍርድ ቤት ጉዳዮች አስተዳደር',
        'app.short_name' => 'Court-MS',
        'app.official_name_en' => 'Administrative Tribunal Case Management System',
        'app.official_name_am' => 'የአስተዳደር ፍርድ ቤት የጉዳዮች አስተዳደር ሥርዓት',
        'general.organization_name' => null,
        'general.system_environment_label' => null,
        'general.login_page_message_en' => null,
        'general.login_page_message_am' => null,
        'general.support_email' => null,
        'general.support_phone' => null,
        'general.default_dashboard_route' => 'court.admin.dashboard',
        'general.help_center_url' => null,
        'general.privacy_policy_url' => null,
        'general.terms_url' => null,
        'general.identity_system_logo_url' => null,
        'general.login_card_photo_urls' => [],
        'general.favicon_url' => null,
        'general.seal_url' => null,
        'localization.default_locale' => 'am',
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
        // appearance.* (bar these three) come from CourtSettingsSchema::APPEARANCE_DEFAULTS.
        'appearance.default_page_size' => 25,
        'appearance.dashboard_layout' => 'executive',
        'appearance.dashboard_refresh_seconds' => 60,
        'security.maintenance_banner_enabled' => false,
        'security.maintenance_banner_message_en' => null,
        'security.maintenance_banner_message_am' => null,
        'public_site.enabled' => false,
    ];

    private ?SystemSetting $row = null;

    private bool $loaded = false;

    /** @return array<string, mixed> */
    public function all(): array
    {
        $settings = [...self::DEFAULTS, ...$this->appearance()];
        $row = $this->row();
        if ($row === null) {
            return $settings;
        }

        $set = function (string $key, mixed $value) use (&$settings): void {
            if ($value !== null && $value !== '') {
                $settings[$key] = $value;
            }
        };
        $set('app.name', $row->app_name);
        $set('app.short_name', $row->short_name);
        $set('general.support_email', $row->contact_email);
        $set('general.support_phone', $row->contact_phone);
        $set('general.favicon_url', $this->asset($row->favicon_path));
        $set('general.identity_system_logo_url', $this->asset($row->logo_path));
        $set('general.seal_url', $this->asset($row->seal_path));
        $set('general.login_page_message_en', $row->welcome_message);
        $set('localization.default_locale', in_array($row->default_locale, ['en', 'am'], true) ? $row->default_locale : null);
        $set('localization.timezone', $row->timezone);
        $set('localization.calendar_system_mode', $row->use_ethiopian_calendar ? 'locale_based' : 'gregorian');
        $settings['security.maintenance_banner_enabled'] = (bool) ($row->maintenance_mode ?? false);

        return $settings;
    }

    /** @return array{brandName: string, brandNameEn: string, brandNameAm: string, shortName: string, officialNameEn: string, officialNameAm: string, logoUrl: ?string, faviconUrl: ?string} */
    public function branding(): array
    {
        $settings = $this->all();
        $en = (string) $settings['app.name'];
        $am = (string) $settings['app.name_am'];

        return [
            'brandName' => app()->getLocale() === 'am' && $am !== '' ? $am : $en,
            'brandNameEn' => $en,
            'brandNameAm' => $am,
            'shortName' => (string) $settings['app.short_name'],
            'officialNameEn' => (string) $settings['app.official_name_en'],
            'officialNameAm' => (string) $settings['app.official_name_am'],
            'logoUrl' => $settings['general.identity_system_logo_url'],
            'faviconUrl' => $settings['general.favicon_url'],
        ];
    }

    /**
     * The appearance settings (System Settings > Appearance), each falling back to
     * the EUISIS default while court-ms has no value of its own.
     *
     * @return array<string, mixed>
     */
    private function appearance(): array
    {
        $row = $this->row();
        $values = [];
        foreach (CourtSettingsSchema::APPEARANCE_DEFAULTS as $key => $default) {
            $stored = $row?->{$key};
            $values['appearance.'.$key] = $stored === null || $stored === '' ? $default : (is_bool($default) ? (bool) $stored : (string) $stored);
        }

        return $values;
    }

    private function row(): ?SystemSetting
    {
        if (! $this->loaded) {
            $this->loaded = true;
            try {
                $this->row = Schema::hasTable('court_system_settings') ? SystemSetting::current() : null;
            } catch (\Throwable) {
                $this->row = null;
            }
        }

        return $this->row;
    }

    /** As the Blade layouts link uploaded images: public storage. */
    private function asset(?string $path): ?string
    {
        return is_string($path) && $path !== '' ? asset('storage/'.$path) : null;
    }
}
