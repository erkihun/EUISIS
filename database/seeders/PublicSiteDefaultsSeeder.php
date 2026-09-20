<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PublicServiceStatus;
use App\Models\PublicNavigationItem;
use App\Models\PublicPageSection;
use App\Models\PublicService;
use App\Services\PublicSite\PublicSiteContent;
use App\Services\PublicSite\PublicSiteSectionRegistry as Registry;
use Illuminate\Database\Seeder;

/**
 * Moves the public site's CURRENT copy into Public Site Management, so the
 * site reads exactly as before on the day the CMS arrives.
 *
 * Every string below is copied verbatim from the i18n files the public pages
 * used until now (resources/js/i18n/{en,am}/home.ts and navigation.ts). Nothing
 * is invented: announcements, FAQs and footer links start empty because no
 * such content existed.
 *
 * Idempotent — rows are created only when missing, so re-running never
 * overwrites what an administrator has since edited.
 */
class PublicSiteDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $this->navigation();
        $this->services();
        $this->sections();

        PublicSiteContent::flush();
    }

    private function navigation(): void
    {
        $items = [
            ['home', 'Home', 'መነሻ', false],
            ['public.announcements', 'Announcements', 'ማስታወቂያዎች', false],
            // The verification entry point cannot be removed or hidden from the
            // CMS: citizens and service providers rely on finding it.
            ['public.verify', 'Verify ID Card', 'መታወቂያ ካርድ ያረጋግጡ', true],
            ['public.services', 'Services', 'አገልግሎቶች', false],
            ['public.support', 'Support', 'ድጋፍ', false],
        ];

        foreach ($items as $index => [$route, $en, $am, $system]) {
            PublicNavigationItem::query()->firstOrCreate(
                ['type' => 'route', 'route_name' => $route],
                [
                    'label_en' => $en,
                    'label_am' => $am,
                    'is_visible' => true,
                    'is_system' => $system,
                    'sort_order' => ($index + 1) * 10,
                ],
            );
        }
    }

    private function services(): void
    {
        $services = [
            [
                'code' => 'ID-CARDS', 'slug' => 'id-cards', 'icon' => 'card',
                'name_en' => 'ID Cards', 'name_am' => 'መታወቂያ ካርዶች',
                'en' => 'Full card lifecycle management: request, print, issue, activate, replace, and revoke.',
                'am' => 'ሙሉ ካርድ ዑደት አስተዳደር፡ ጥያቄ፣ ህትመት፣ ስጦታ፣ ማንቃት፣ መቀየር፣ እና መሰረዝ።',
                'action_route' => null,
            ],
            [
                'code' => 'CAFETERIA', 'slug' => 'cafeteria', 'icon' => 'store',
                'name_en' => 'Cafeteria', 'name_am' => 'ካፌቴሪያ',
                'en' => 'Subsidized meal access and transaction tracking for employees at authorized cafeteria providers.',
                'am' => 'በተፈቀደላቸው የካፌቴሪያ አቅራቢዎች ለሠራተኞች የሚደጎም የምግብ አገልግሎት እና የግብይት ክትትል።',
                'action_route' => null,
            ],
            [
                'code' => 'TRANSFERS', 'slug' => 'transfers', 'icon' => 'transfer',
                'name_en' => 'Transfers', 'name_am' => 'ዝውውሮች',
                'en' => 'Structured transfer workflow with multi-party confirmation, ensuring continuity of employee identity.',
                'am' => 'ብዙ ወገን ማረጋገጫ ያለው የሠራተኛ ማንነት ቀጣይነት የሚያረጋግጥ ዝውውር ሂደት።',
                'action_route' => 'public.announcements',
            ],
            [
                'code' => 'ID-VERIFICATION', 'slug' => 'id-verification', 'icon' => 'shield',
                'name_en' => 'ID Verification', 'name_am' => 'የመታወቂያ ማረጋገጫ',
                'en' => "Scan or enter a card reference to verify an employee's ID and service entitlements in real time.",
                'am' => 'የሠራተኛውን መታወቂያ እና የአገልግሎት መብቶች በቅጽበት ለማረጋገጥ የካርድ ቁጥሩን ያስገቡ ወይም ይቃኙ።',
                'action_route' => 'public.verify',
            ],
        ];

        foreach ($services as $index => $service) {
            PublicService::query()->firstOrCreate(
                ['code' => $service['code']],
                [
                    'slug' => $service['slug'],
                    'icon' => $service['icon'],
                    'name_en' => $service['name_en'],
                    'name_am' => $service['name_am'],
                    'short_description_en' => $service['en'],
                    'short_description_am' => $service['am'],
                    'action_route' => $service['action_route'],
                    'status' => PublicServiceStatus::Published->value,
                    'is_featured' => true,
                    'sort_order' => ($index + 1) * 10,
                    'published_at' => now(),
                ],
            );
        }
    }

    private function sections(): void
    {
        $sections = [
            Registry::PAGE_HOME => [
                'intro' => [
                    'title_en' => 'Unified Employee ID & Service Integration System',
                    'title_am' => 'የተቀናጀ የሠራተኛ መታወቂያ እና አገልግሎት ስርዓት',
                    'subtitle_en' => 'Secure digital identity and service access management for Addis Ababa City Administration employees.',
                    'subtitle_am' => 'ለአዲስ አበባ ከተማ አስተዳደር ሠራተኞች ደህንነቱ የተጠበቀ ዲጂታል መታወቂያ እና የአገልግሎት ፈቃድ አስተዳደር።',
                    'options' => [
                        'primary_cta_route' => 'public.verify',
                        'primary_cta_label_en' => 'Verify ID Card',
                        'primary_cta_label_am' => 'መታወቂያ ካርድ ያረጋግጡ',
                        'secondary_cta_route' => 'public.services',
                        'secondary_cta_label_en' => 'Services',
                        'secondary_cta_label_am' => 'አገልግሎቶች',
                    ],
                ],
                'featured_services' => [
                    'title_en' => 'Public Services', 'title_am' => 'ይፋዊ አገልግሎቶች',
                    'options' => ['max_items' => 4],
                ],
                'latest_announcements' => [
                    'title_en' => 'Announcements', 'title_am' => 'ማስታወቂያዎች',
                    'options' => ['max_items' => 3],
                ],
                'verification' => [
                    'title_en' => 'Verify Employee ID Card', 'title_am' => 'የሠራተኛ መታወቂያ ካርድ ያረጋግጡ',
                    'body_en' => 'Enter the card reference number or scan the QR code to verify.',
                    'body_am' => 'ካርዱን ለማረጋገጥ የካርድ ቁጥሩን ያስገቡ ወይም QR ኮዱን ይቃኙ።',
                    'options' => ['button_label_en' => 'Verify ID Card', 'button_label_am' => 'መታወቂያ ካርድ ያረጋግጡ'],
                ],
                'support' => [
                    'title_en' => 'Support & Help', 'title_am' => 'ድጋፍ እና እገዛ',
                    'body_en' => 'Contact information and help resources.',
                    'body_am' => 'የድጋፍ አድራሻዎች እና የእገዛ ሀብቶች።',
                    'options' => ['button_label_en' => 'Support', 'button_label_am' => 'ድጋፍ'],
                ],
            ],
            Registry::PAGE_ANNOUNCEMENTS => [
                'header' => ['title_en' => 'Announcements', 'title_am' => 'ማስታወቂያዎች'],
            ],
            Registry::PAGE_SERVICES => [
                'header' => [
                    'title_en' => 'Public Services', 'title_am' => 'ይፋዊ አገልግሎቶች',
                    'subtitle_en' => 'Service offerings available to city administration employees.',
                    'subtitle_am' => 'ለከተማ አስተዳደር ሠራተኞች የሚቀርቡ አገልግሎቶች።',
                ],
            ],
            Registry::PAGE_SUPPORT => [
                'header' => [
                    'title_en' => 'Support & Help', 'title_am' => 'ድጋፍ እና እገዛ',
                    'subtitle_en' => 'Contact information and help resources.',
                    'subtitle_am' => 'የድጋፍ አድራሻዎች እና የእገዛ ሀብቶች።',
                ],
                'contact' => [
                    'body_en' => 'For support, please contact your organization administrator.',
                    'body_am' => 'ለድጋፍ፣ እባክዎ የተቋሙን አስተዳዳሪ ያነጋግሩ።',
                ],
                'faq' => [],
            ],
            Registry::PAGE_VERIFY => [
                'header' => [
                    'title_en' => 'Verify Employee ID Card', 'title_am' => 'የሠራተኛ መታወቂያ ካርድ ያረጋግጡ',
                    'subtitle_en' => 'Enter the card reference number or scan the QR code to verify.',
                    'subtitle_am' => 'ካርዱን ለማረጋገጥ የካርድ ቁጥሩን ያስገቡ ወይም QR ኮዱን ይቃኙ።',
                ],
                // Empty until an administrator writes guidance; an empty slot renders nothing.
                'scan_help' => [],
                'otp_help' => [],
                'notice' => [],
            ],
        ];

        foreach (Registry::definitions() as $page => $pageSections) {
            $order = 10;
            foreach (array_keys($pageSections) as $key) {
                $content = $sections[$page][$key] ?? [];

                PublicPageSection::query()->firstOrCreate(
                    ['page' => $page, 'section_key' => $key],
                    [
                        'is_visible' => true,
                        'sort_order' => $order,
                        'title_en' => $content['title_en'] ?? null,
                        'title_am' => $content['title_am'] ?? null,
                        'subtitle_en' => $content['subtitle_en'] ?? null,
                        'subtitle_am' => $content['subtitle_am'] ?? null,
                        'body_en' => $content['body_en'] ?? null,
                        'body_am' => $content['body_am'] ?? null,
                        'options' => $content['options'] ?? null,
                    ],
                );
                $order += 10;
            }
        }
    }
}
