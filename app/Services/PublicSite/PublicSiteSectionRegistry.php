<?php

declare(strict_types=1);

namespace App\Services\PublicSite;

/**
 * Every editable content slot on the public site, fixed in code.
 *
 * This is what keeps the CMS structured rather than a page builder: an admin
 * edits the words inside a slot, may hide or reorder the optional ones, and
 * cannot invent a new slot, inject markup or change where a slot's buttons go
 * beyond the routes allowed here.
 *
 * Each section declares:
 *   - fields:   which bilingual text fields the editor shows
 *   - options:  structured settings with validation rules
 *   - hideable: whether the admin may switch it off
 *   - sortable: whether it takes part in page ordering
 */
final class PublicSiteSectionRegistry
{
    public const PAGE_HOME = 'home';

    public const PAGE_ANNOUNCEMENTS = 'announcements';

    public const PAGE_SERVICES = 'services';

    public const PAGE_SUPPORT = 'support';

    public const PAGE_VERIFY = 'verify';

    /** Pages that carry SEO metadata. */
    public const PAGES = [
        self::PAGE_HOME,
        self::PAGE_ANNOUNCEMENTS,
        self::PAGE_SERVICES,
        self::PAGE_SUPPORT,
        self::PAGE_VERIFY,
    ];

    /**
     * @return array<string, array<string, array{
     *     fields: list<string>,
     *     options: array<string, list<string>>,
     *     hideable: bool,
     *     sortable: bool
     * }>>
     */
    public static function definitions(): array
    {
        $cta = ['nullable', 'string', 'max:60'];

        return [
            self::PAGE_HOME => [
                'intro' => [
                    'fields' => ['title', 'subtitle', 'body'],
                    'options' => [
                        'primary_cta_route' => ['nullable', 'string', 'max:120'],
                        'primary_cta_label_en' => $cta,
                        'primary_cta_label_am' => $cta,
                        'secondary_cta_route' => ['nullable', 'string', 'max:120'],
                        'secondary_cta_label_en' => $cta,
                        'secondary_cta_label_am' => $cta,
                    ],
                    'hideable' => false,
                    'sortable' => false,
                ],
                'featured_services' => [
                    'fields' => ['title', 'subtitle'],
                    'options' => ['max_items' => ['required', 'integer', 'min:1', 'max:12']],
                    'hideable' => true,
                    'sortable' => true,
                ],
                'latest_announcements' => [
                    'fields' => ['title', 'subtitle'],
                    'options' => ['max_items' => ['required', 'integer', 'min:1', 'max:10']],
                    'hideable' => true,
                    'sortable' => true,
                ],
                // The button always goes to the Verify page. Only its words are editable.
                'verification' => [
                    'fields' => ['title', 'body'],
                    'options' => ['button_label_en' => $cta, 'button_label_am' => $cta],
                    'hideable' => true,
                    'sortable' => true,
                ],
                'support' => [
                    'fields' => ['title', 'body'],
                    'options' => ['button_label_en' => $cta, 'button_label_am' => $cta],
                    'hideable' => true,
                    'sortable' => true,
                ],
            ],
            self::PAGE_ANNOUNCEMENTS => [
                'header' => ['fields' => ['title', 'subtitle'], 'options' => [], 'hideable' => false, 'sortable' => false],
            ],
            self::PAGE_SERVICES => [
                'header' => ['fields' => ['title', 'subtitle'], 'options' => [], 'hideable' => false, 'sortable' => false],
            ],
            self::PAGE_SUPPORT => [
                'header' => ['fields' => ['title', 'subtitle'], 'options' => [], 'hideable' => false, 'sortable' => false],
                'contact' => ['fields' => ['title', 'body'], 'options' => [], 'hideable' => false, 'sortable' => false],
                'faq' => ['fields' => ['title'], 'options' => [], 'hideable' => true, 'sortable' => false],
            ],
            /*
             * Presentation only. OTP length, lifetime, attempt limits, rate
             * limits and the list of fields shown after verification live in
             * PublicIdCheckerService / AppServiceProvider and are deliberately
             * not representable here.
             */
            self::PAGE_VERIFY => [
                'header' => ['fields' => ['title', 'subtitle'], 'options' => [], 'hideable' => false, 'sortable' => false],
                'scan_help' => ['fields' => ['body'], 'options' => [], 'hideable' => true, 'sortable' => false],
                'otp_help' => ['fields' => ['body'], 'options' => [], 'hideable' => true, 'sortable' => false],
                'notice' => ['fields' => ['body'], 'options' => [], 'hideable' => true, 'sortable' => false],
            ],
        ];
    }

    public static function has(string $page, string $sectionKey): bool
    {
        return isset(self::definitions()[$page][$sectionKey]);
    }

    /** @return array{fields: list<string>, options: array<string, list<string>>, hideable: bool, sortable: bool} */
    public static function definition(string $page, string $sectionKey): array
    {
        return self::definitions()[$page][$sectionKey];
    }
}
