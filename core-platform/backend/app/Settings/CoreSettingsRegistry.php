<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Every setting the Core owns, in one place: type, default, labels in both
 * languages, validation and whether the sign-in page may read it.
 *
 * Keys, labels, defaults and rules are the EUISIS System Settings ones
 * (App\Services\SystemSettings\SystemSettingsRegistry) wherever EUISIS has the
 * same setting, so a value means the same thing in both systems. `workforce`
 * holds the Core's own rules. Public settings carry no secrets.
 */
final class CoreSettingsRegistry
{
    public const GROUPS = ['general', 'localization', 'appearance', 'security', 'id_cards', 'workforce', 'numbering'];

    /** @return array<string, array<string, array<string, mixed>>> group => key => definition */
    public static function definitions(): array
    {
        $color = ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        // A code format: free text with {seq:N} (required) and optionally {year}.
        $format = ['required', 'string', 'max:40', 'regex:/\{seq:[1-9]\}/'];
        $formatHelpEn = 'Used when a code is left blank. {seq:6} is the next number padded to 6 digits; {year} is the current year.';
        $formatHelpAm = 'ኮድ ባዶ ሲቀር ይሠራል። {seq:6} ቀጣዩ ቁጥር በ6 አሃዝ ሲሆን {year} የአሁኑ ዓመት ነው።';

        return [
            'general' => [
                'application_name' => self::field('string', 'Agelgay', 'Brand Name', 'የምርት ስም', 'The product brand in Latin script: browser title, sign-in page and navigation in English.', 'የምርቱ ስም በላቲን ፊደል፦ በእንግሊዝኛ ገጾች የአሳሽ ርዕስ፣ የመግቢያ ገጽ እና አሰሳ።', true, ['required', 'string', 'max:160']),
                'application_name_am' => self::field('string', 'አገልጋይ', 'Brand Name (Amharic)', 'የምርት ስም (አማርኛ)', 'The same brand in Ge\'ez script, shown wherever the pages are in Amharic.', 'ያው የምርት ስም በግዕዝ ፊደል፤ ገጾቹ በአማርኛ በሚታዩበት ሁሉ ይታያል።', true, ['nullable', 'string', 'max:160']),
                'application_short_name' => self::field('string', 'AA-IEMSS', 'Official Short Name', 'ይፋዊ አጭር ስም', 'The official system identifier: formal references and generated file names.', 'ይፋዊ የሥርዓት መለያ፦ በይፋዊ ማጣቀሻዎች እና በሚፈጠሩ ፋይሎች ስም ላይ።', true, ['required', 'string', 'max:80']),
                'official_name_en' => self::field('string', 'Addis Ababa Integrated Employee Management & Service System', 'Official English Name', 'ይፋዊ የእንግሊዝኛ ስም', 'The full official name, used as the sign-in subtitle.', 'በመግቢያ ንዑስ ርዕስ ላይ የሚውል ሙሉ ይፋዊ ስም።', true, ['required', 'string', 'max:200']),
                'official_name_am' => self::field('string', 'የአዲስ አበባ የተቀናጀ የሰራተኛ አስተዳደርና አገልግሎት ሥርዓት', 'Official Amharic Name', 'ይፋዊ የአማርኛ ስም', 'The full official name in Amharic, used wherever the official English name is.', 'ይፋዊው የእንግሊዝኛ ስም በሚውልበት ሁሉ የሚውል ሙሉ የአማርኛ ይፋዊ ስም።', true, ['required', 'string', 'max:200']),
                'organization_name' => self::field('string', 'Addis Ababa City Administration', 'Organization Name', 'የድርጅቱ ስም', 'The owning public institution displayed across the product shell.', 'በሙሉ የሥርዓቱ ገጽታ ላይ የሚታየው ባለቤት የመንግሥት ተቋም ስም።', true, ['required', 'string', 'max:200']),
                'system_environment_label' => self::field('select', 'production', 'System Environment Label', 'የሥርዓት አካባቢ መለያ', 'Any value but production is shown in the sidebar, so test data is never mistaken for live.', 'ከproduction ውጭ ያለ ማንኛውም እሴት በጎን አሞሌው ላይ ይታያል፤ የሙከራ መረጃ ከእውነተኛው ጋር እንዳይምታታ።', true, ['required', 'in:production,staging,testing,local,development,demo,training'], ['production', 'staging', 'testing', 'local', 'development', 'demo', 'training']),
                'login_page_message_en' => self::field('text', null, 'Login Page Message English', 'የመግቢያ ገጽ መልዕክት እንግሊዝኛ', 'Optional English message on the sign-in page.', 'በመግቢያ ገጹ ላይ የሚታይ አማራጭ የእንግሊዝኛ መልዕክት።', true, ['nullable', 'string', 'max:2000']),
                'login_page_message_am' => self::field('text', null, 'Login Page Message Amharic', 'የመግቢያ ገጽ መልዕክት አማርኛ', 'Optional Amharic message on the sign-in page.', 'በመግቢያ ገጹ ላይ የሚታይ አማራጭ የአማርኛ መልዕክት።', true, ['nullable', 'string', 'max:2000']),
            ],
            'localization' => [
                'default_locale' => self::field('select', 'en', 'Default Locale', 'ነባሪ ቋንቋ', 'Used when a person has not chosen a language on this device.', 'ሰውየው በዚህ መሣሪያ ቋንቋ ካልመረጠ የሚጠቀም ነባሪ ቋንቋ።', true, ['required', 'in:en,am'], ['en', 'am']),
            ],
            'appearance' => [
                'default_theme' => self::field('select', 'system', 'Default Theme', 'ነባሪ ገጽታ', null, null, true, ['required', 'in:light,dark,system'], ['light', 'dark', 'system']),
                'table_density' => self::field('select', 'comfortable', 'Table Density', 'የሰንጠረዥ ጥግግት', null, null, true, ['required', 'in:compact,comfortable,spacious'], ['compact', 'comfortable', 'spacious']),
                'button_style' => self::field('select', 'rounded', 'Button Style', 'የአዝራር ቅጥ', null, null, true, ['required', 'in:rounded,soft,square'], ['rounded', 'soft', 'square']),
                'card_radius' => self::field('select', 'xl', 'Card Radius', 'የካርድ ክብ መጠን', null, null, true, ['required', 'in:sm,md,lg,xl,2xl'], ['sm', 'md', 'lg', 'xl', '2xl']),
                'primary_color' => self::field('color', '#122170', 'Primary Color', 'ዋና ቀለም', null, null, true, $color),
                'secondary_color' => self::field('color', '#1D3084', 'Secondary Color', 'ሁለተኛ ቀለም', null, null, true, $color),
                'accent_color' => self::field('color', '#D12908', 'Accent Color', 'አክሰንት ቀለም', null, null, true, $color),
                'sidebar_color' => self::field('color', '#FFFFFF', 'Sidebar Color', 'የጎን አሞሌ ቀለም', 'Background of the main navigation sidebar. Text and icons adjust automatically for contrast.', 'የዋናው የዳሰሳ ጎን አሞሌ ዳራ። ጽሑፍና አዶዎች ለንጽጽር በራስ-ሰር ይስተካከላሉ።', true, $color),
                'allow_user_theme_switching' => self::field('boolean', true, 'Allow User Theme Switching', 'የተጠቃሚ ገጽታ መቀየር ይፈቀድ', null, null, true, ['required', 'boolean']),
                'sidebar_compact_default' => self::field('boolean', false, 'Sidebar Compact Default', 'ነባሪ የጎን አሞሌ ጥቅጥቅ', null, null, true, ['required', 'boolean']),
                'show_breadcrumbs' => self::field('boolean', true, 'Show Breadcrumbs', 'የመንገድ አሻራዎች ይታዩ', null, null, true, ['required', 'boolean']),
                'show_language_switcher' => self::field('boolean', true, 'Show Language Switcher', 'የቋንቋ መቀየሪያ ይታይ', null, null, true, ['required', 'boolean']),
                'enable_ui_animations' => self::field('boolean', true, 'Enable UI Animations', 'የUI እነማዎች ይንቁ', null, null, true, ['required', 'boolean']),
                'sticky_table_headers' => self::field('boolean', true, 'Sticky Table Headers', 'የሚቆዩ የሰንጠረዥ ራሶች', null, null, true, ['required', 'boolean']),
                'default_page_size' => self::field('integer', 25, 'Default Page Size', 'ነባሪ የገፅ መጠን', 'Rows on each page of every list.', 'በእያንዳንዱ ዝርዝር ገጽ ላይ ያሉ ረድፎች።', true, ['required', 'integer', 'min:10', 'max:100']),
                'logo_position' => self::field('select', 'start', 'Logo Position', 'የአርማ ቦታ', null, null, true, ['required', 'in:start,center'], ['start', 'center']),
            ],
            'security' => [
                'session_timeout_minutes' => self::field('integer', 480, 'Session Timeout Minutes', 'የክፍለ-ጊዜ ጊዜ ማብቂያ ደቂቃዎች', 'How long a sign-in lasts before the person must sign in again. Applies to new sign-ins.', 'ሰውየው እንደገና መግባት ከማስፈለጉ በፊት አንድ መግቢያ የሚቆይበት ጊዜ። ለአዲስ መግቢያዎች ይሠራል።', false, ['required', 'integer', 'min:5', 'max:1440']),
                'max_login_attempts' => self::field('integer', 5, 'Max Login Attempts', 'ከፍተኛ የመግቢያ ሙከራዎች', 'Wrong passwords allowed for one e-mail from one address before sign-in is locked.', 'መግቢያ ከመቆለፉ በፊት ለአንድ ኢሜይል ከአንድ አድራሻ የሚፈቀዱ የተሳሳቱ የይለፍ ቃሎች።', false, ['required', 'integer', 'min:1', 'max:20']),
                'lockout_minutes' => self::field('integer', 15, 'Lockout Minutes', 'የመቆለፊያ ደቂቃዎች', 'How long sign-in stays locked after too many wrong passwords.', 'ከብዙ የተሳሳቱ የይለፍ ቃሎች በኋላ መግቢያ ተቆልፎ የሚቆይበት ጊዜ።', false, ['required', 'integer', 'min:1', 'max:1440']),
                'api_rate_limit_per_minute' => self::field('integer', 240, 'API Rate Limit Per Minute', 'የAPI ደቂቃ ገደብ', 'Requests one account may make each minute.', 'አንድ መለያ በደቂቃ ሊያቀርብ የሚችለው የጥያቄ ብዛት።', false, ['required', 'integer', 'min:10', 'max:1000']),
                'verification_rate_limit_per_minute' => self::field('integer', 60, 'Verification Rate Limit Per Minute', 'የማረጋገጫ ደቂቃ ገደብ', 'Card checks one account may make each minute.', 'አንድ መለያ በደቂቃ ሊያደርገው የሚችለው የካርድ ማረጋገጫ ብዛት።', false, ['required', 'integer', 'min:10', 'max:1000']),
                'maintenance_banner_enabled' => self::field('boolean', false, 'Maintenance Banner Enabled', 'የጥገና ባነር ነቅቷል', null, null, true, ['required', 'boolean']),
                'maintenance_banner_message_en' => self::field('text', null, 'Maintenance Banner Message English', 'የጥገና ባነር መልዕክት እንግሊዝኛ', null, null, true, ['nullable', 'string', 'max:500']),
                'maintenance_banner_message_am' => self::field('text', null, 'Maintenance Banner Message Amharic', 'የጥገና ባነር መልዕክት አማርኛ', null, null, true, ['nullable', 'string', 'max:500']),
            ],
            'id_cards' => [
                'city_name_en' => self::field('string', 'Addis Ababa City Administration', 'City Name (English)', 'የከተማ ስም (እንግሊዝኛ)', 'City name shown on the sign-in page and the sidebar (English).', 'በመግቢያ ገጹና በጎን አሞሌው ላይ የሚታይ የከተማ ስም (እንግሊዝኛ)።', true, ['required', 'string', 'max:120']),
                'city_name_am' => self::field('string', 'አዲስ አበባ ከተማ አስተዳደር', 'City Name (Amharic)', 'የከተማ ስም (አማርኛ)', 'City name shown on the sign-in page and the sidebar (Amharic).', 'በመግቢያ ገጹና በጎን አሞሌው ላይ የሚታይ የከተማ ስም (አማርኛ)።', true, ['required', 'string', 'max:120']),
                'bureau_name_en' => self::field('string', 'Public Service & HRD Bureau', 'Bureau Name (English)', 'የቢሮ ስም (እንግሊዝኛ)', 'Bureau name shown on the sign-in page (English).', 'በመግቢያ ገጹ ላይ የሚታይ የቢሮ ስም (እንግሊዝኛ)።', true, ['required', 'string', 'max:120']),
                'bureau_name_am' => self::field('string', 'የሲቪል ሰርቪስና ሰው ሀብት ልማት ቢሮ', 'Bureau Name (Amharic)', 'የቢሮ ስም (አማርኛ)', 'Bureau name shown on the sign-in page (Amharic).', 'በመግቢያ ገጹ ላይ የሚታይ የቢሮ ስም (አማርኛ)።', true, ['required', 'string', 'max:120']),
                'validity_years' => self::field('integer', 2, 'Card Validity (Years)', 'የካርድ የአገልግሎት ዘመን (ዓመታት)', 'How long a newly approved card is valid.', 'አዲስ የጸደቀ ካርድ የሚያገለግልበት ጊዜ።', false, ['required', 'integer', 'min:1', 'max:10']),
            ],
            'numbering' => [
                'organization_code_format' => self::field('string', 'ORG-{seq:5}', 'Organization Code Format', 'የተቋም ኮድ ቅርጸት', $formatHelpEn, $formatHelpAm, false, $format),
                'unit_code_format' => self::field('string', 'UNIT-{seq:5}', 'Unit Code Format', 'የሥራ ክፍል ኮድ ቅርጸት', $formatHelpEn, $formatHelpAm, false, $format),
                'position_code_format' => self::field('string', 'POS-{seq:6}', 'Position Code Format', 'የሥራ መደብ ኮድ ቅርጸት', $formatHelpEn, $formatHelpAm, false, $format),
                'employee_number_format' => self::field('string', 'EMP-{seq:6}', 'Employee Number Format', 'የሠራተኛ ቁጥር ቅርጸት', $formatHelpEn.' An issued employee number never changes.', $formatHelpAm.' የተሰጠ የሠራተኛ ቁጥር ፈጽሞ አይለወጥም።', false, $format),
                'card_number_format' => self::field('string', 'CARD-{year}-{seq:6}', 'Card Number Format', 'የካርድ ቁጥር ቅርጸት', $formatHelpEn, $formatHelpAm, false, $format),
            ],
            'workforce' => [
                'default_seat_capacity' => self::field('integer', 1, 'Seats Without an Establishment', 'ያለ ጸደቀ መደብ የመቀመጫ ብዛት', 'How many people may hold a position that has no approved establishment in force.', 'በሥራ ላይ ያለ የጸደቀ የመደብ ብዛት የሌለውን የሥራ መደብ ስንት ሰው ሊይዘው እንደሚችል።', false, ['required', 'integer', 'min:1', 'max:50']),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function definition(string $group, string $key): ?array
    {
        return self::definitions()[$group][$key] ?? null;
    }

    /**
     * @param  list<string>|null  $options
     * @param  list<string>  $rules
     * @return array<string, mixed>
     */
    private static function field(string $type, mixed $default, string $labelEn, string $labelAm, ?string $descriptionEn, ?string $descriptionAm, bool $public, array $rules, ?array $options = null): array
    {
        return [
            'type' => $type,
            'default' => $default,
            'label_en' => $labelEn,
            'label_am' => $labelAm,
            'description_en' => $descriptionEn,
            'description_am' => $descriptionAm,
            'is_public' => $public,
            'options' => $options,
            'rules' => $rules,
        ];
    }
}
