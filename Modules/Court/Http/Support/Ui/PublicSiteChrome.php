<?php

declare(strict_types=1);

namespace Modules\Court\Http\Support\Ui;

use Modules\Court\Domain\Models\HomeSetting;
use Modules\Court\Application\Support\EuisisParties;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * What the React public layout (resources/js/court/public/PublicLayout.tsx)
 * shows around a page: the landing manager's footer and contact settings in
 * the request's language, the user manual link, and where parties work on
 * their cases (the EUISIS employee portal). Read through the same cache keys
 * as the home page (PublicHomeController), so the landing manager's flush
 * refreshes both. A text saved in English only is shown in English, except
 * the footer description, whose reviewed Amharic translation is kept.
 */
final class PublicSiteChrome
{
    /** Contact, office and social fields the layout shows; their `_am` twins are picked for Amharic. */
    private const FOOTER_FIELDS = [
        'description', 'authority_name', 'contact_phone', 'contact_email', 'contact_address', 'office_hours', 'map_url',
        'social_facebook', 'social_twitter', 'social_linkedin', 'social_youtube', 'social_telegram', 'social_instagram',
    ];

    private const TRANSLATED = ['description', 'authority_name', 'contact_address', 'office_hours'];

    /** @return array{footer: array<string, string|null>|null, userManualEnabled: bool, userManualTitle: string|null, portalUrl: string|null} */
    public static function props(): array
    {
        $footer = Cache::remember('home_footer', 3600,
            fn () => Schema::hasTable('court_home_settings') ? HomeSetting::getJson('footer.settings') : null
        );
        $manual = Cache::remember('home_user_manual', 3600,
            fn () => Schema::hasTable('court_home_settings') ? HomeSetting::getJson('user_manual.settings') : []
        );
        $manual = is_array($manual) ? $manual : [];
        $locale = app()->getLocale() === 'am' ? 'am' : 'en';

        $value = function (array $saved, string $field) use ($locale): ?string {
            $read = fn (string $key): ?string => isset($saved[$key]) && is_scalar($saved[$key]) && trim((string) $saved[$key]) !== '' ? trim((string) $saved[$key]) : null;
            $text = $locale === 'am' && in_array($field, self::TRANSLATED, true) ? ($read("{$field}_am") ?? ($field === 'description' ? null : $read($field))) : $read($field);

            return match (true) {
                $text === null => null,
                // Social and map links land in an href: http(s) only, whatever was stored (EditorLink).
                str_starts_with($field, 'social_') || $field === 'map_url' => EditorLink::safe($text, allowRelative: false),
                default => $text,
            };
        };

        $footer = is_array($footer) && $footer !== []
            ? array_combine(self::FOOTER_FIELDS, array_map(fn (string $field): ?string => $value($footer, $field), self::FOOTER_FIELDS))
            : null;
        $title = trim((string) ($manual["title_{$locale}"] ?? ''));

        return [
            'footer' => $footer,
            'userManualEnabled' => (bool) ($manual['is_active'] ?? false)
                && (! empty($manual['content_en']) || ! empty($manual['content_am'])),
            'userManualTitle' => $title !== '' ? $title : null,
            // Flow 6: parties file and follow their cases in the EUISIS employee portal.
            'portalUrl' => EuisisParties::portalClosed() ? EuisisParties::portalUrl() : null,
        ];
    }
}
