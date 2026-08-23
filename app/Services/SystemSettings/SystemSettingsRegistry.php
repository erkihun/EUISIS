<?php

declare(strict_types=1);

namespace App\Services\SystemSettings;

use App\Enums\IdCardTemplate;

class SystemSettingsRegistry
{
    public const GROUP_GENERAL = 'general';

    public const GROUP_LOCALIZATION = 'localization';

    public const GROUP_NOTIFICATIONS = 'notifications';

    public const GROUP_EMAIL = 'email';

    public const GROUP_SMS = 'sms';

    public const GROUP_TELEGRAM = 'telegram';

    public const GROUP_SECURITY = 'security';

    public const GROUP_APPEARANCE = 'appearance';

    public const GROUP_ID_CARDS = 'id_cards';

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function definitions(): array
    {
        return [
            self::GROUP_GENERAL => [
                'application_name' => self::field(
                    type: 'string',
                    default: 'Addis Ababa Employee Unified ID & Service Platform',
                    labelEn: 'Application Name',
                    labelAm: 'የመተግበሪያው ስም',
                    descriptionEn: 'The full product name shown in the browser title and public branding.',
                    descriptionAm: 'በአሳሽ ርዕስ እና በህዝብ ብራንዲንግ የሚታይ ሙሉ የስርዓቱ ስም።',
                    isPublic: true,
                    isRequired: true,
                    validationRules: ['required', 'string', 'max:160'],
                    sortOrder: 10,
                ),
                'application_short_name' => self::field(
                    type: 'string',
                    default: 'AA Employee ID',
                    labelEn: 'Application Short Name',
                    labelAm: 'አጭር የስርዓት ስም',
                    descriptionEn: 'Used where space is constrained, such as the sidebar and mobile header.',
                    descriptionAm: 'ቦታ ሲጠበቅ እንደ ጎን አሞሌ እና ሞባይል ራስጌ የሚጠቀም አጭር ስም።',
                    isPublic: true,
                    isRequired: true,
                    validationRules: ['required', 'string', 'max:80'],
                    sortOrder: 20,
                ),
                'organization_name' => self::field(
                    type: 'string',
                    default: 'Addis Ababa City Administration',
                    labelEn: 'Organization Name',
                    labelAm: 'የድርጅቱ ስም',
                    descriptionEn: 'The owning public institution displayed across the product shell.',
                    descriptionAm: 'በሙሉ የስርዓቱ ገጽታ ላይ የሚታየው ባለቤት የመንግስት ተቋም ስም።',
                    isPublic: true,
                    isRequired: true,
                    validationRules: ['required', 'string', 'max:200'],
                    sortOrder: 30,
                ),
                'default_dashboard_route' => self::field(
                    type: 'select',
                    default: 'dashboard',
                    labelEn: 'Default Dashboard Route',
                    labelAm: 'ነባሪ የዳሽቦርድ መንገድ',
                    descriptionEn: 'The route used when users are sent to the primary landing dashboard.',
                    descriptionAm: 'ተጠቃሚዎች ወደ ነባሪ ዳሽቦርድ ሲመራሩ የሚጠቀሙት መንገድ።',
                    isPublic: true,
                    isRequired: true,
                    options: ['dashboard', 'employees.index', 'organizations.index'],
                    validationRules: ['required', 'string', 'max:120'],
                    sortOrder: 40,
                ),
                'support_email' => self::field(
                    type: 'email',
                    default: null,
                    labelEn: 'Support Email',
                    labelAm: 'የድጋፍ ኢሜይል',
                    descriptionEn: 'Safe public support email shown in help and login surfaces.',
                    descriptionAm: 'በእርዳታ እና በመግቢያ ገፆች ላይ የሚታይ የድጋፍ ኢሜይል።',
                    isPublic: true,
                    validationRules: ['nullable', 'email', 'max:160'],
                    sortOrder: 50,
                ),
                'support_phone' => self::field(
                    type: 'phone',
                    default: null,
                    labelEn: 'Support Phone',
                    labelAm: 'የድጋፍ ስልክ',
                    descriptionEn: 'Safe public support phone number.',
                    descriptionAm: 'ለህዝብ የሚታይ የድጋፍ ስልክ ቁጥር።',
                    isPublic: true,
                    validationRules: ['nullable', 'string', 'max:40'],
                    sortOrder: 60,
                ),
                'identity_system_logo' => self::field(
                    type: 'image',
                    default: null,
                    labelEn: 'Identity System Logo',
                    labelAm: 'የስርዓቱ አርማ',
                    descriptionEn: 'Displayed in the application shell and login surfaces.',
                    descriptionAm: 'በስርዓቱ ቅርፀ ገጽታ እና በመግቢያ ገፆች ላይ የሚታይ አርማ።',
                    isPublic: true,
                    validationRules: ['nullable', 'file', 'mimes:jpg,jpeg,png,webp'],
                    sortOrder: 70,
                ),
                'favicon' => self::field(
                    type: 'file',
                    default: null,
                    labelEn: 'Favicon',
                    labelAm: 'ፋቪኮን',
                    descriptionEn: 'Browser tab icon for the system.',
                    descriptionAm: 'በአሳሽ ትር ላይ የሚታይ አዶ።',
                    isPublic: true,
                    validationRules: ['nullable', 'file', 'mimes:ico,png,webp'],
                    sortOrder: 80,
                ),
                'seal' => self::field(
                    type: 'image',
                    default: null,
                    labelEn: 'Seal',
                    labelAm: 'ማህተም',
                    descriptionEn: 'Official system seal. PNG files only.',
                    descriptionAm: 'የሥርዓቱ ኦፊሴላዊ ማህተም። PNG ፋይል ብቻ።',
                    isPublic: true,
                    validationRules: ['nullable', 'file', 'image', 'mimes:png'],
                    sortOrder: 85,
                ),
                'system_environment_label' => self::field(
                    type: 'select',
                    default: 'production',
                    labelEn: 'System Environment Label',
                    labelAm: 'የስርዓት አካባቢ መለያ',
                    descriptionEn: 'A visible environment label shown across the application header.',
                    descriptionAm: 'በስርዓቱ ራስጌ ላይ የሚታይ የአካባቢ መለያ።',
                    isPublic: true,
                    options: ['production', 'staging', 'testing', 'local', 'development', 'demo', 'training'],
                    validationRules: ['nullable', 'string', 'in:production,staging,testing,local,development,demo,training'],
                    sortOrder: 90,
                ),
                'help_center_url' => self::field(
                    type: 'url',
                    default: null,
                    labelEn: 'Help Center URL',
                    labelAm: 'የእርዳታ ማዕከል URL',
                    descriptionEn: 'Optional help center link for public support surfaces.',
                    descriptionAm: 'በድጋፍ ገጾች ላይ የሚታይ አማራጭ የእርዳታ ማዕከል አገናኝ።',
                    isPublic: true,
                    validationRules: ['nullable', 'url', 'max:255'],
                    sortOrder: 100,
                ),
                'privacy_policy_url' => self::field(
                    type: 'url',
                    default: null,
                    labelEn: 'Privacy Policy URL',
                    labelAm: 'የግላዊነት ፖሊሲ URL',
                    descriptionEn: 'Optional privacy policy link.',
                    descriptionAm: 'አማራጭ የግላዊነት ፖሊሲ አገናኝ።',
                    isPublic: true,
                    validationRules: ['nullable', 'url', 'max:255'],
                    sortOrder: 110,
                ),
                'terms_url' => self::field(
                    type: 'url',
                    default: null,
                    labelEn: 'Terms URL',
                    labelAm: 'የውሎች URL',
                    descriptionEn: 'Optional terms or policy link.',
                    descriptionAm: 'አማራጭ የውሎች ወይም የፖሊሲ አገናኝ።',
                    isPublic: true,
                    validationRules: ['nullable', 'url', 'max:255'],
                    sortOrder: 120,
                ),
                'login_page_message_en' => self::field(
                    type: 'text',
                    default: null,
                    labelEn: 'Login Page Message English',
                    labelAm: 'የመግቢያ ገጽ መልዕክት እንግሊዝኛ',
                    descriptionEn: 'Optional English message on the login screen.',
                    descriptionAm: 'በመግቢያ ገጹ ላይ የሚታይ አማራጭ የእንግሊዝኛ መልዕክት።',
                    isPublic: true,
                    validationRules: ['nullable', 'string', 'max:2000'],
                    sortOrder: 130,
                ),
                'login_page_message_am' => self::field(
                    type: 'text',
                    default: null,
                    labelEn: 'Login Page Message Amharic',
                    labelAm: 'የመግቢያ ገጽ መልዕክት አማርኛ',
                    descriptionEn: 'Optional Amharic message on the login screen.',
                    descriptionAm: 'በመግቢያ ገጹ ላይ የሚታይ አማራጭ የአማርኛ መልዕክት።',
                    isPublic: true,
                    validationRules: ['nullable', 'string', 'max:2000'],
                    sortOrder: 140,
                ),
            ],

            self::GROUP_LOCALIZATION => [
                'default_locale' => self::field(
                    type: 'select',
                    default: 'en',
                    labelEn: 'Default Locale',
                    labelAm: 'ነባሪ ቋንቋ',
                    descriptionEn: 'Used when a user has not selected a language preference.',
                    descriptionAm: 'ተጠቃሚው የቋንቋ ምርጫ ካልወሰነ የሚጠቀም ነባሪ ቋንቋ።',
                    isPublic: true,
                    isRequired: true,
                    options: ['en', 'am'],
                    validationRules: ['required', 'in:en,am'],
                    sortOrder: 10,
                ),
                'fallback_locale' => self::field(
                    type: 'select',
                    default: 'en',
                    labelEn: 'Fallback Locale',
                    labelAm: 'መጠባበቂያ ቋንቋ',
                    descriptionEn: 'Used when a translation key is missing.',
                    descriptionAm: 'የትርጉም ቁልፍ ካልተገኘ የሚጠቀም ቋንቋ።',
                    isPublic: true,
                    isRequired: true,
                    options: ['en', 'am'],
                    validationRules: ['required', 'in:en,am'],
                    sortOrder: 20,
                ),
                'timezone' => self::field(
                    type: 'timezone',
                    default: 'Africa/Addis_Ababa',
                    labelEn: 'Timezone',
                    labelAm: 'የጊዜ ዞን',
                    descriptionEn: 'Primary timezone for date and time rendering.',
                    descriptionAm: 'ለቀን እና ሰዓት ማሳያ የሚጠቀም ዋና የጊዜ ዞን።',
                    isPublic: true,
                    isRequired: true,
                    validationRules: ['required', 'timezone'],
                    sortOrder: 30,
                ),
                'date_format' => self::field(
                    type: 'select',
                    default: 'Y-m-d',
                    labelEn: 'Date Format',
                    labelAm: 'የቀን ቅርጸት',
                    descriptionEn: 'Date format exposed to the frontend.',
                    descriptionAm: 'ለፊት ገጽ የሚላክ የቀን ቅርጸት።',
                    isPublic: true,
                    isRequired: true,
                    options: ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'],
                    validationRules: ['required', 'string', 'max:32'],
                    sortOrder: 40,
                ),
                'datetime_format' => self::field(
                    type: 'string',
                    default: 'Y-m-d H:i',
                    labelEn: 'Datetime Format',
                    labelAm: 'የቀን እና ሰዓት ቅርጸት',
                    descriptionEn: 'Datetime format exposed to the frontend.',
                    descriptionAm: 'ለፊት ገጽ የሚላክ የቀን እና ሰዓት ቅርጸት።',
                    isPublic: true,
                    isRequired: true,
                    validationRules: ['required', 'string', 'max:32'],
                    sortOrder: 50,
                ),
                'supported_locales' => self::field(
                    type: 'multiselect',
                    default: ['en', 'am'],
                    labelEn: 'Supported Locales',
                    labelAm: 'የሚደገፉ ቋንቋዎች',
                    descriptionEn: 'Controls which locales the language switcher exposes.',
                    descriptionAm: 'የቋንቋ መቀየሪያው የሚያሳያቸውን ቋንቋዎች ይቆጣጠራል።',
                    isPublic: true,
                    isRequired: true,
                    options: ['en', 'am'],
                    validationRules: ['required', 'array', 'min:1'],
                    sortOrder: 60,
                ),
                'first_day_of_week' => self::field(
                    type: 'select',
                    default: '1',
                    labelEn: 'First Day of Week',
                    labelAm: 'የሳምንቱ መጀመሪያ ቀን',
                    descriptionEn: 'Used by date pickers and calendars.',
                    descriptionAm: 'በቀን መምረጫ እና በቀን መቁጠሪያ የሚጠቀም።',
                    isPublic: true,
                    options: ['0', '1', '6'],
                    validationRules: ['required', 'in:0,1,6'],
                    sortOrder: 70,
                ),
                'number_format' => self::field(
                    type: 'select',
                    default: '1,234.56',
                    labelEn: 'Number Format',
                    labelAm: 'የቁጥር ቅርጸት',
                    descriptionEn: 'Optional number formatting hint for UI rendering.',
                    descriptionAm: 'ለፊት ገጽ ቁጥር ማሳያ የሚያግዝ ቅርጸት።',
                    isPublic: true,
                    options: ['1,234.56', '1 234.56'],
                    validationRules: ['nullable', 'string', 'max:32'],
                    sortOrder: 80,
                ),
                'organization_name_display' => self::field(
                    type: 'select',
                    default: 'english',
                    labelEn: 'Organization Name Display',
                    labelAm: 'የድርጅት ስም ማሳያ',
                    descriptionEn: 'Controls whether English, Amharic, or mixed organization names are preferred.',
                    descriptionAm: 'የድርጅት ስሞች በእንግሊዝኛ፣ በአማርኛ ወይም በጥምር እንዲታዩ ይቆጣጠራል።',
                    isPublic: true,
                    options: ['english', 'amharic', 'both'],
                    validationRules: ['nullable', 'string', 'max:32'],
                    sortOrder: 90,
                ),
                'employee_name_display' => self::field(
                    type: 'select',
                    default: 'full_name',
                    labelEn: 'Employee Name Display',
                    labelAm: 'የሰራተኛ ስም ማሳያ',
                    descriptionEn: 'Controls the preferred employee name format for UI lists.',
                    descriptionAm: 'በፊት ገጽ ዝርዝሮች የሚታየውን የሰራተኛ ስም ቅርጸት ይቆጣጠራል።',
                    isPublic: true,
                    options: ['full_name', 'first_last'],
                    validationRules: ['nullable', 'string', 'max:32'],
                    sortOrder: 100,
                ),
                'calendar_system_mode' => self::field(
                    type: 'select',
                    default: 'locale_based',
                    labelEn: 'Calendar System Mode',
                    labelAm: 'የቀን አቆጣጠር ስርዓት',
                    descriptionEn: 'locale_based uses Ethiopian for Amharic users and Gregorian for English users. gregorian_only forces Gregorian everywhere. ethiopian_only forces Ethiopian everywhere.',
                    descriptionAm: 'locale_based ለአማርኛ ተጠቃሚዎች ኢትዮጵያዊ፣ ለእንግሊዝኛ ተጠቃሚዎች ጎርጎሮሳዊ ቀን አቆጣጠር ይጠቀማል።',
                    isPublic: true,
                    isRequired: true,
                    options: ['locale_based', 'gregorian_only', 'ethiopian_only'],
                    validationRules: ['required', 'in:locale_based,gregorian_only,ethiopian_only'],
                    sortOrder: 110,
                ),
            ],

            self::GROUP_NOTIFICATIONS => [
                'database_notifications_enabled' => self::field(type: 'boolean', default: true, labelEn: 'Database Notifications Enabled', labelAm: 'የውስጥ ማስታወቂያዎች ነቅተዋል', validationRules: ['required', 'boolean'], sortOrder: 10),
                'email_notifications_enabled' => self::field(type: 'boolean', default: false, labelEn: 'Email Notifications Enabled', labelAm: 'የኢሜይል ማስታወቂያዎች ነቅተዋል', validationRules: ['required', 'boolean'], sortOrder: 20),
                'sms_notifications_enabled' => self::field(type: 'boolean', default: false, labelEn: 'SMS Notifications Enabled', labelAm: 'የSMS ማስታወቂያዎች ነቅተዋል', validationRules: ['required', 'boolean'], sortOrder: 30),
                'telegram_notifications_enabled' => self::field(type: 'boolean', default: false, labelEn: 'Telegram Notifications Enabled', labelAm: 'የTelegram ማስታወቂያዎች ነቅተዋል', validationRules: ['required', 'boolean'], sortOrder: 40),
                'notification_retry_attempts' => self::field(type: 'integer', default: 3, labelEn: 'Notification Retry Attempts', labelAm: 'የማስታወቂያ ድጋሚ ሙከራዎች', validationRules: ['required', 'integer', 'min:0', 'max:10'], sortOrder: 50),
                'notification_queue_name' => self::field(type: 'string', default: 'default', labelEn: 'Notification Queue Name', labelAm: 'የማስታወቂያ ሰልፍ ስም', validationRules: ['nullable', 'string', 'max:80'], sortOrder: 60),
                'notify_admin_on_security_event' => self::field(type: 'boolean', default: true, labelEn: 'Notify Admin On Security Event', labelAm: 'በደህንነት ክስተት ላይ አስተዳዳሪን አሳውቅ', validationRules: ['required', 'boolean'], sortOrder: 70),
                'notify_user_on_card_ready' => self::field(type: 'boolean', default: true, labelEn: 'Notify User On Card Ready', labelAm: 'ካርድ ሲዘጋጅ ተጠቃሚን አሳውቅ', validationRules: ['required', 'boolean'], sortOrder: 80),
                'notify_user_on_transfer_approved' => self::field(type: 'boolean', default: true, labelEn: 'Notify User On Transfer Approved', labelAm: 'ዝውውር ሲፀድቅ ተጠቃሚን አሳውቅ', validationRules: ['required', 'boolean'], sortOrder: 90),
            ],

            self::GROUP_EMAIL => [
                'mail_mailer' => self::field(type: 'select', default: 'smtp', labelEn: 'Mail Mailer', labelAm: 'የኢሜይል ማስላኪያ', options: ['smtp', 'log', 'sendmail', 'ses'], validationRules: ['required', 'in:smtp,log,sendmail,ses'], sortOrder: 10),
                'mail_host' => self::field(type: 'string', default: null, labelEn: 'Mail Host', labelAm: 'የኢሜይል አስተናጋጅ', validationRules: ['nullable', 'string', 'max:160'], sortOrder: 20),
                'mail_port' => self::field(type: 'integer', default: 587, labelEn: 'Mail Port', labelAm: 'የኢሜይል ፖርት', validationRules: ['nullable', 'integer', 'between:1,65535'], sortOrder: 30),
                'mail_username' => self::field(type: 'encrypted', default: null, labelEn: 'Mail Username', labelAm: 'የኢሜይል ተጠቃሚ', isEncrypted: true, validationRules: ['nullable', 'string', 'max:200'], sortOrder: 40),
                'mail_password' => self::field(type: 'password', default: null, labelEn: 'Mail Password', labelAm: 'የኢሜይል የይለፍ ቃል', isEncrypted: true, validationRules: ['nullable', 'string', 'max:200'], sortOrder: 50),
                'mail_encryption' => self::field(type: 'select', default: 'tls', labelEn: 'Mail Encryption', labelAm: 'የኢሜይል ምስጢራዊነት', options: ['tls', 'ssl', 'none'], validationRules: ['required', 'in:tls,ssl,none'], sortOrder: 60),
                'mail_from_address' => self::field(type: 'email', default: null, labelEn: 'Mail From Address', labelAm: 'የላኪ ኢሜይል', validationRules: ['nullable', 'email', 'max:160'], sortOrder: 70),
                'mail_from_name' => self::field(type: 'string', default: null, labelEn: 'Mail From Name', labelAm: 'የላኪ ስም', validationRules: ['nullable', 'string', 'max:160'], sortOrder: 80),
                'email_test_recipient' => self::field(type: 'email', default: null, labelEn: 'Email Test Recipient', labelAm: 'የኢሜይል ሙከራ ተቀባይ', validationRules: ['nullable', 'email', 'max:160'], sortOrder: 90),
                'email_rate_limit_per_minute' => self::field(type: 'integer', default: 60, labelEn: 'Email Rate Limit Per Minute', labelAm: 'የኢሜይል ደቂቃ ገደብ', validationRules: ['required', 'integer', 'min:1', 'max:1000'], sortOrder: 100),
                'email_queue_enabled' => self::field(type: 'boolean', default: true, labelEn: 'Email Queue Enabled', labelAm: 'የኢሜይል ሰልፍ ነቅቷል', validationRules: ['required', 'boolean'], sortOrder: 110),
            ],

            self::GROUP_SMS => [
                'sms_provider' => self::field(type: 'select', default: 'disabled', labelEn: 'SMS Provider', labelAm: 'የSMS አቅራቢ', options: ['disabled', 'generic_http', 'local_gateway', 'ethio_telecom', 'custom'], validationRules: ['required', 'in:disabled,generic_http,local_gateway,ethio_telecom,custom'], sortOrder: 10),
                'sms_api_url' => self::field(type: 'url', default: null, labelEn: 'SMS API URL', labelAm: 'የSMS API URL', isEncrypted: true, validationRules: ['nullable', 'url', 'max:255'], sortOrder: 20),
                'sms_api_key' => self::field(type: 'password', default: null, labelEn: 'SMS API Key', labelAm: 'የSMS API ቁልፍ', isEncrypted: true, validationRules: ['nullable', 'string', 'max:255'], sortOrder: 30),
                'sms_sender_id' => self::field(type: 'string', default: 'AAID', labelEn: 'SMS Sender ID', labelAm: 'የSMS ላኪ መለያ', validationRules: ['nullable', 'string', 'max:40'], sortOrder: 40),
                'sms_default_country_code' => self::field(type: 'string', default: '+251', labelEn: 'Default Country Code', labelAm: 'ነባሪ የአገር ኮድ', validationRules: ['nullable', 'string', 'max:8'], sortOrder: 50),
                'sms_timeout_seconds' => self::field(type: 'integer', default: 10, labelEn: 'SMS Timeout Seconds', labelAm: 'የSMS ጊዜ ገደብ ሰከንዶች', validationRules: ['required', 'integer', 'min:1', 'max:120'], sortOrder: 60),
                'sms_test_phone' => self::field(type: 'string', default: null, labelEn: 'SMS Test Phone', labelAm: 'የSMS ሙከራ ስልክ', validationRules: ['nullable', 'string', 'max:40'], sortOrder: 70),
                'sms_rate_limit_per_minute' => self::field(type: 'integer', default: 30, labelEn: 'SMS Rate Limit Per Minute', labelAm: 'የSMS ደቂቃ ገደብ', validationRules: ['required', 'integer', 'min:1', 'max:500'], sortOrder: 80),
            ],

            self::GROUP_TELEGRAM => [
                'telegram_bot_token' => self::field(type: 'password', default: null, labelEn: 'Telegram Bot Token', labelAm: 'የTelegram Bot Token', isEncrypted: true, validationRules: ['nullable', 'string', 'max:255'], sortOrder: 10),
                'telegram_default_chat_id' => self::field(type: 'encrypted', default: null, labelEn: 'Telegram Default Chat ID', labelAm: 'ነባሪ የTelegram ውይይት መለያ', isEncrypted: true, validationRules: ['nullable', 'string', 'max:100'], sortOrder: 20),
                'telegram_webhook_url' => self::field(type: 'url', default: null, labelEn: 'Telegram Webhook URL', labelAm: 'የTelegram Webhook URL', validationRules: ['nullable', 'url', 'max:255'], sortOrder: 30),
                'telegram_notifications_channel' => self::field(type: 'string', default: null, labelEn: 'Telegram Notifications Channel', labelAm: 'የTelegram ማስታወቂያ ቻናል', validationRules: ['nullable', 'string', 'max:100'], sortOrder: 40),
                'telegram_timeout_seconds' => self::field(type: 'integer', default: 10, labelEn: 'Telegram Timeout Seconds', labelAm: 'የTelegram ጊዜ ገደብ ሰከንዶች', validationRules: ['required', 'integer', 'min:1', 'max:120'], sortOrder: 50),
                'telegram_test_chat_id' => self::field(type: 'string', default: null, labelEn: 'Telegram Test Chat ID', labelAm: 'የTelegram ሙከራ ውይይት መለያ', validationRules: ['nullable', 'string', 'max:100'], sortOrder: 60),
            ],

            self::GROUP_SECURITY => [
                'password_min_length' => self::field(type: 'integer', default: 12, labelEn: 'Password Min Length', labelAm: 'ዝቅተኛ የይለፍ ቃል ርዝመት', isRequired: true, validationRules: ['required', 'integer', 'min:8', 'max:64'], sortOrder: 10),
                'session_timeout_minutes' => self::field(type: 'integer', default: 120, labelEn: 'Session Timeout Minutes', labelAm: 'የክፍለ-ጊዜ ጊዜ ማብቂያ ደቂቃዎች', isRequired: true, validationRules: ['required', 'integer', 'min:5', 'max:1440'], sortOrder: 20),
                'max_upload_size_mb' => self::field(type: 'integer', default: 10, labelEn: 'Max Upload Size MB', labelAm: 'ከፍተኛ የመጫኛ መጠን MB', isRequired: true, validationRules: ['required', 'integer', 'min:1', 'max:50'], sortOrder: 30),
                'password_complexity_enabled' => self::field(type: 'boolean', default: true, labelEn: 'Password Complexity Enabled', labelAm: 'የይለፍ ቃል ጥንካሬ ነቅቷል', validationRules: ['required', 'boolean'], sortOrder: 40),
                'maintenance_banner_enabled' => self::field(type: 'boolean', default: false, labelEn: 'Maintenance Banner Enabled', labelAm: 'የጥገና ባነር ነቅቷል', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 50),
                'maintenance_banner_message_en' => self::field(type: 'text', default: null, labelEn: 'Maintenance Banner Message English', labelAm: 'የጥገና ባነር መልዕክት እንግሊዝኛ', isPublic: true, validationRules: ['nullable', 'string', 'max:500'], sortOrder: 60),
                'maintenance_banner_message_am' => self::field(type: 'text', default: null, labelEn: 'Maintenance Banner Message Amharic', labelAm: 'የጥገና ባነር መልዕክት አማርኛ', isPublic: true, validationRules: ['nullable', 'string', 'max:500'], sortOrder: 70),
                'allowed_file_types' => self::field(type: 'multiselect', default: ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx'], labelEn: 'Allowed File Types', labelAm: 'የተፈቀዱ የፋይል አይነቶች', options: ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx'], validationRules: ['required', 'array', 'min:1'], sortOrder: 80),
                'allowed_upload_mime_types' => self::field(type: 'multiselect', default: ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], labelEn: 'Allowed Upload MIME Types', labelAm: 'የተፈቀዱ የMIME አይነቶች', validationRules: ['required', 'array', 'min:1'], sortOrder: 90),
                'mfa_enabled' => self::field(
                    type: 'boolean',
                    default: true,
                    labelEn: 'Enable MFA',
                    labelAm: 'MFA አንቃ',
                    descriptionEn: 'Master switch for multi-factor authentication enforcement configured below.',
                    descriptionAm: 'ከዚህ በታች ለተዋቀረው የብዙ-ደረጃ ማረጋገጫ አስፈጻሚነት ዋና ማብሪያ።',
                    validationRules: ['required', 'boolean'],
                    sortOrder: 94,
                ),
                'mfa_required_for_all' => self::field(
                    type: 'boolean',
                    default: false,
                    labelEn: 'Require MFA For All Users',
                    labelAm: 'ለሁሉም ተጠቃሚዎች MFA አስፈልግ',
                    descriptionEn: 'When enabled, every user must set up and use MFA regardless of role.',
                    descriptionAm: 'ሲነቃ፣ እያንዳንዱ ተጠቃሚ ሚና ሳይለይ MFA ማዋቀር እና መጠቀም አለበት።',
                    validationRules: ['required', 'boolean'],
                    sortOrder: 96,
                ),
                'mfa_required_role_ids' => self::field(
                    type: 'multiselect',
                    default: [],
                    labelEn: 'Require MFA For Selected Roles',
                    labelAm: 'ለተመረጡ ሚናዎች MFA አስፈልግ',
                    descriptionEn: 'MFA can be enforced globally or only for selected roles.',
                    descriptionAm: 'MFA በአጠቃላይ ወይም ለተመረጡ ሚናዎች ብቻ ማስፈጸም ይቻላል።',
                    validationRules: ['nullable', 'array'],
                    sortOrder: 98,
                ),
                // Legacy admin-only flag. Kept for backward compatibility with
                // existing rows/env; hidden from the settings UI and mapped to
                // config('security.mfa_privileged_roles') during enforcement.
                'require_mfa_for_admins' => self::field(type: 'boolean', default: false, labelEn: 'Require MFA For Admins (Legacy)', labelAm: 'ለአስተዳዳሪዎች MFA አስፈልጋል (የቆየ)', validationRules: ['required', 'boolean'], sortOrder: 100),
                'max_login_attempts' => self::field(type: 'integer', default: 5, labelEn: 'Max Login Attempts', labelAm: 'ከፍተኛ የመግቢያ ሙከራዎች', validationRules: ['required', 'integer', 'min:1', 'max:20'], sortOrder: 110),
                'lockout_minutes' => self::field(type: 'integer', default: 15, labelEn: 'Lockout Minutes', labelAm: 'የመቆለፊያ ደቂቃዎች', validationRules: ['required', 'integer', 'min:1', 'max:1440'], sortOrder: 120),
                'password_expiry_days' => self::field(type: 'integer', default: 90, labelEn: 'Password Expiry Days', labelAm: 'የይለፍ ቃል ማለቂያ ቀናት', validationRules: ['required', 'integer', 'min:0', 'max:365'], sortOrder: 130),
                'force_https' => self::field(type: 'boolean', default: false, labelEn: 'Force HTTPS', labelAm: 'HTTPS አስገድድ', validationRules: ['required', 'boolean'], sortOrder: 140),
                'audit_retention_days' => self::field(type: 'integer', default: 365, labelEn: 'Audit Retention Days', labelAm: 'የኦዲት ማቆያ ቀናት', validationRules: ['required', 'integer', 'min:30', 'max:3650'], sortOrder: 150),
                'sensitive_export_requires_reason' => self::field(type: 'boolean', default: true, labelEn: 'Sensitive Export Requires Reason', labelAm: 'ስሜታዊ ማውጫ ምክንያት ይፈልጋል', validationRules: ['required', 'boolean'], sortOrder: 160),
                'api_rate_limit_per_minute' => self::field(type: 'integer', default: 120, labelEn: 'API Rate Limit Per Minute', labelAm: 'የAPI ደቂቃ ገደብ', validationRules: ['required', 'integer', 'min:10', 'max:1000'], sortOrder: 170),
                'verification_rate_limit_per_minute' => self::field(type: 'integer', default: 120, labelEn: 'Verification Rate Limit Per Minute', labelAm: 'የማረጋገጫ ደቂቃ ገደብ', validationRules: ['required', 'integer', 'min:10', 'max:1000'], sortOrder: 180),
            ],

            self::GROUP_APPEARANCE => [
                'default_theme' => self::field(type: 'select', default: 'system', labelEn: 'Default Theme', labelAm: 'ነባሪ ገጽታ', isPublic: true, options: ['light', 'dark', 'system'], validationRules: ['required', 'in:light,dark,system'], sortOrder: 10),
                'table_density' => self::field(type: 'select', default: 'comfortable', labelEn: 'Table Density', labelAm: 'የሰንጠረዥ ጥግግት', isPublic: true, options: ['compact', 'comfortable', 'spacious'], validationRules: ['required', 'in:compact,comfortable,spacious'], sortOrder: 20),
                'button_style' => self::field(type: 'select', default: 'rounded', labelEn: 'Button Style', labelAm: 'የአዝራር ቅጥ', isPublic: true, options: ['rounded', 'soft', 'square'], validationRules: ['required', 'in:rounded,soft,square'], sortOrder: 30),
                'card_radius' => self::field(type: 'select', default: 'xl', labelEn: 'Card Radius', labelAm: 'የካርድ ክብ መጠን', isPublic: true, options: ['sm', 'md', 'lg', 'xl', '2xl'], validationRules: ['required', 'in:sm,md,lg,xl,2xl'], sortOrder: 40),
                'primary_color' => self::field(type: 'color', default: '#2563EB', labelEn: 'Primary Color', labelAm: 'ዋና ቀለም', isPublic: true, validationRules: ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'], sortOrder: 50),
                'secondary_color' => self::field(type: 'color', default: '#1E40AF', labelEn: 'Secondary Color', labelAm: 'ሁለተኛ ቀለም', isPublic: true, validationRules: ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'], sortOrder: 60),
                'accent_color' => self::field(type: 'color', default: '#F97316', labelEn: 'Accent Color', labelAm: 'አክሰንት ቀለም', isPublic: true, validationRules: ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'], sortOrder: 70),
                'allow_user_theme_switching' => self::field(type: 'boolean', default: true, labelEn: 'Allow User Theme Switching', labelAm: 'የተጠቃሚ ገጽታ መቀየር ፍቀድ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 80),
                'sidebar_compact_default' => self::field(type: 'boolean', default: false, labelEn: 'Sidebar Compact Default', labelAm: 'ነባሪ የጎን አሞሌ ጥቅጥቅ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 90),
                'show_breadcrumbs' => self::field(type: 'boolean', default: true, labelEn: 'Show Breadcrumbs', labelAm: 'የመንገድ አሻራዎችን አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 100),
                'show_language_switcher' => self::field(type: 'boolean', default: true, labelEn: 'Show Language Switcher', labelAm: 'የቋንቋ መቀየሪያ አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 110),
                'dashboard_layout' => self::field(type: 'select', default: 'executive', labelEn: 'Dashboard Layout', labelAm: 'የዳሽቦርድ ቅጥ', isPublic: true, options: ['executive', 'compact'], validationRules: ['required', 'in:executive,compact'], sortOrder: 120),
                'dashboard_refresh_seconds' => self::field(type: 'integer', default: 60, labelEn: 'Dashboard Refresh Seconds', labelAm: 'የዳሽቦርድ ማደስ ሰከንዶች', isPublic: true, validationRules: ['required', 'integer', 'min:15', 'max:3600'], sortOrder: 130),
                'enable_ui_animations' => self::field(type: 'boolean', default: true, labelEn: 'Enable UI Animations', labelAm: 'የUI ንቅናቄዎችን አንቃ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 140),
                'sticky_table_headers' => self::field(type: 'boolean', default: true, labelEn: 'Sticky Table Headers', labelAm: 'የሚቆዩ የሰንጠረዥ ራሶች', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 150),
                'default_page_size' => self::field(type: 'integer', default: 25, labelEn: 'Default Page Size', labelAm: 'ነባሪ የገፅ መጠን', isPublic: true, validationRules: ['required', 'integer', 'min:10', 'max:100'], sortOrder: 160),
                'logo_position' => self::field(type: 'select', default: 'start', labelEn: 'Logo Position', labelAm: 'የአርማ ቦታ', isPublic: true, options: ['start', 'center'], validationRules: ['required', 'in:start,center'], sortOrder: 170),
                'navigation_style' => self::field(type: 'select', default: 'sidebar', labelEn: 'Navigation Style', labelAm: 'የአሰሳ ቅጥ', isPublic: true, options: ['sidebar'], validationRules: ['required', 'in:sidebar'], sortOrder: 180),
            ],

            self::GROUP_ID_CARDS => [
                'template' => self::field(
                    type: 'select',
                    default: IdCardTemplate::Classic->value,
                    labelEn: 'ID Card Template',
                    labelAm: 'የመታወቂያ ካርድ ንድፍ',
                    descriptionEn: 'Choose the layout used for card previews, printing, and exports.',
                    descriptionAm: 'ለካርድ ቅድመ እይታ፣ ህትመት እና ወደ ፋይል ማውጣት የሚያገለግለውን ንድፍ ይምረጡ።',
                    isPublic: true,
                    isRequired: true,
                    options: IdCardTemplate::values(),
                    validationRules: ['required', 'in:'.implode(',', IdCardTemplate::values())],
                    sortOrder: 1,
                ),
                // Front card — background
                'front_bg_from' => self::field(
                    type: 'color',
                    default: '#1D4ED8',
                    labelEn: 'Front Card Gradient Start',
                    labelAm: 'የፊት ካርድ ቅርፀ ቀለም መጀመሪያ',
                    descriptionEn: 'Starting colour of the front card gradient.',
                    descriptionAm: 'የፊት ካርድ ቀለም ቅርፀት መጀመሪያ ቀለም።',
                    isPublic: true,
                    validationRules: ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                    sortOrder: 10,
                ),
                'front_bg_to' => self::field(
                    type: 'color',
                    default: '#1E3A8A',
                    labelEn: 'Front Card Gradient End',
                    labelAm: 'የፊት ካርድ ቅርፀ ቀለም መጨረሻ',
                    descriptionEn: 'Ending colour of the front card gradient.',
                    descriptionAm: 'የፊት ካርድ ቀለም ቅርፀት መጨረሻ ቀለም።',
                    isPublic: true,
                    validationRules: ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                    sortOrder: 20,
                ),
                // Front card — typography
                'front_text_primary' => self::field(
                    type: 'color',
                    default: '#FFFFFF',
                    labelEn: 'Front Primary Text Color',
                    labelAm: 'የፊት ካርድ ዋና ጽሑፍ ቀለም',
                    descriptionEn: 'Colour for the employee name and main labels.',
                    descriptionAm: 'ለሰራተኛ ስም እና ዋና ለፊደሎች የሚጠቀም ቀለም።',
                    isPublic: true,
                    validationRules: ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                    sortOrder: 30,
                ),
                'front_text_secondary' => self::field(
                    type: 'color',
                    default: '#BFDBFE',
                    labelEn: 'Front Secondary Text Color',
                    labelAm: 'የፊት ካርድ ሁለተኛ ጽሑፍ ቀለም',
                    descriptionEn: 'Colour for job title, department, and field labels.',
                    descriptionAm: 'ለሥራ ማዕረግ፣ ለዲፓርትመንት እና ለፊደል ምልክቶች የሚጠቀም ቀለም።',
                    isPublic: true,
                    validationRules: ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                    sortOrder: 40,
                ),
                'front_name_font_size' => self::field(
                    type: 'select',
                    default: 'sm',
                    labelEn: 'Employee Name Font Size',
                    labelAm: 'የሰራተኛ ስም ፊደል መጠን',
                    descriptionEn: 'Font size class for the employee full name on the front card.',
                    descriptionAm: 'የሰራተኛ ሙሉ ስም ፊደል መጠን።',
                    isPublic: true,
                    options: ['xs', 'sm', 'base', 'lg'],
                    validationRules: ['required', 'in:xs,sm,base,lg'],
                    sortOrder: 50,
                ),
                'front_label_font_size' => self::field(
                    type: 'select',
                    default: 'xs',
                    labelEn: 'Field Label Font Size',
                    labelAm: 'የፊደል ምልክት ፊደል መጠን',
                    descriptionEn: 'Font size class for field labels (ID, Card No, dates) on the front card.',
                    descriptionAm: 'ለፊደል ምልክቶች (መለያ፣ ካርድ ቁጥር፣ ቀናት) ፊደል መጠን።',
                    isPublic: true,
                    options: ['xs', 'sm'],
                    validationRules: ['required', 'in:xs,sm'],
                    sortOrder: 60,
                ),
                // Front card — header text
                'city_name_en' => self::field(
                    type: 'string',
                    default: 'Addis Ababa City Administration',
                    labelEn: 'City Name (English)',
                    labelAm: 'የከተማ ስም (እንግሊዝኛ)',
                    descriptionEn: 'City name shown in the card header (English).',
                    descriptionAm: 'በካርድ ራስጌ ላይ የሚታይ የከተማ ስም (እንግሊዝኛ)።',
                    isPublic: true,
                    validationRules: ['required', 'string', 'max:120'],
                    sortOrder: 70,
                ),
                'city_name_am' => self::field(
                    type: 'string',
                    default: 'አዲስ አበባ ከተማ አስተዳደር',
                    labelEn: 'City Name (Amharic)',
                    labelAm: 'የከተማ ስም (አማርኛ)',
                    descriptionEn: 'City name shown in the card header (Amharic).',
                    descriptionAm: 'በካርድ ራስጌ ላይ የሚታይ የከተማ ስም (አማርኛ)።',
                    isPublic: true,
                    validationRules: ['required', 'string', 'max:120'],
                    sortOrder: 80,
                ),
                'bureau_name_en' => self::field(
                    type: 'string',
                    default: 'Public Service & HRD Bureau',
                    labelEn: 'Bureau Name (English)',
                    labelAm: 'የቢሮ ስም (እንግሊዝኛ)',
                    descriptionEn: 'Bureau sub-title shown in the card header (English).',
                    descriptionAm: 'በካርድ ራስጌ ላይ የሚታይ የቢሮ ስም (እንግሊዝኛ)።',
                    isPublic: true,
                    validationRules: ['required', 'string', 'max:120'],
                    sortOrder: 90,
                ),
                'bureau_name_am' => self::field(
                    type: 'string',
                    default: 'የሲቪል ሰርቪስና ሰው ሃብት ልማት ቢሮ',
                    labelEn: 'Bureau Name (Amharic)',
                    labelAm: 'የቢሮ ስም (አማርኛ)',
                    descriptionEn: 'Bureau sub-title shown in the card header (Amharic).',
                    descriptionAm: 'በካርድ ራስጌ ላይ የሚታይ የቢሮ ስም (አማርኛ)።',
                    isPublic: true,
                    validationRules: ['required', 'string', 'max:120'],
                    sortOrder: 100,
                ),
                'show_organization_logo' => self::field(
                    type: 'boolean',
                    default: true,
                    labelEn: 'Show Organization Logo',
                    labelAm: 'የድርጅት አርማ አሳይ',
                    descriptionEn: 'Show the organization logo in the front card header.',
                    descriptionAm: 'በፊት ካርድ ራስጌ ላይ የድርጅት አርማ ያሳዩ።',
                    isPublic: true,
                    validationRules: ['required', 'boolean'],
                    sortOrder: 110,
                ),
                'show_photo' => self::field(type: 'boolean', default: true, labelEn: 'Show Employee Photo', labelAm: 'የሠራተኛ ፎቶ አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 111),
                'show_full_name_en' => self::field(type: 'boolean', default: true, labelEn: 'Show Full Name in English', labelAm: 'ሙሉ ስም በእንግሊዝኛ አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 112),
                'show_full_name_am' => self::field(type: 'boolean', default: true, labelEn: 'Show Full Name in Amharic', labelAm: 'ሙሉ ስም በአማርኛ አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 113),
                'show_employee_number' => self::field(type: 'boolean', default: true, labelEn: 'Show Employee Number', labelAm: 'የሠራተኛ ቁጥር አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 114),
                'show_card_number' => self::field(type: 'boolean', default: true, labelEn: 'Show Card Number', labelAm: 'የካርድ ቁጥር አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 115),
                'show_organization' => self::field(type: 'boolean', default: true, labelEn: 'Show Organization', labelAm: 'ተቋም አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 116),
                'show_organization_unit' => self::field(type: 'boolean', default: true, labelEn: 'Show Organization Unit', labelAm: 'የተቋም የሥራ ክፍል አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 117),
                'show_position' => self::field(type: 'boolean', default: true, labelEn: 'Show Position', labelAm: 'የሥራ መደብ አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 118),
                'show_job_grade' => self::field(type: 'boolean', default: true, labelEn: 'Show Job Grade', labelAm: 'የሥራ ደረጃ አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 119),
                'show_employment_status' => self::field(type: 'boolean', default: true, labelEn: 'Show Employment Status', labelAm: 'የቅጥር ሁኔታ አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 120),
                'show_issue_date' => self::field(type: 'boolean', default: true, labelEn: 'Show Issue Date', labelAm: 'የተሰጠበትን ቀን አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 121),
                'show_expiry_date' => self::field(type: 'boolean', default: true, labelEn: 'Show Expiry Date', labelAm: 'የሚያበቃበትን ቀን አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 122),
                'show_signature' => self::field(type: 'boolean', default: false, labelEn: 'Show Authorized Signature', labelAm: 'የባለሥልጣን ፊርማ አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 123),
                'show_qr' => self::field(type: 'boolean', default: true, labelEn: 'Show QR Code', labelAm: 'QR ኮድ አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 124),
                'show_return_notice' => self::field(type: 'boolean', default: true, labelEn: 'Show Return If Found Notice', labelAm: 'ከተገኘ ይመለስ ማስታወቂያ አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 125),
                'show_emergency_contact' => self::field(type: 'boolean', default: true, labelEn: 'Show Emergency Contact', labelAm: 'የአደጋ ጊዜ መገናኛ አሳይ', isPublic: true, validationRules: ['required', 'boolean'], sortOrder: 126),
                // Back card — background
                'back_bg_from' => self::field(
                    type: 'color',
                    default: '#1E293B',
                    labelEn: 'Back Card Gradient Start',
                    labelAm: 'የኋላ ካርድ ቅርፀ ቀለም መጀመሪያ',
                    descriptionEn: 'Starting colour of the back card gradient.',
                    descriptionAm: 'የኋላ ካርድ ቀለም ቅርፀት መጀመሪያ ቀለም።',
                    isPublic: true,
                    validationRules: ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                    sortOrder: 120,
                ),
                'back_bg_to' => self::field(
                    type: 'color',
                    default: '#0F172A',
                    labelEn: 'Back Card Gradient End',
                    labelAm: 'የኋላ ካርድ ቅርፀ ቀለም መጨረሻ',
                    descriptionEn: 'Ending colour of the back card gradient.',
                    descriptionAm: 'የኋላ ካርድ ቀለም ቅርፀት መጨረሻ ቀለም።',
                    isPublic: true,
                    validationRules: ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                    sortOrder: 130,
                ),
                'back_text_color' => self::field(
                    type: 'color',
                    default: '#94A3B8',
                    labelEn: 'Back Card Text Color',
                    labelAm: 'የኋላ ካርድ ጽሑፍ ቀለም',
                    descriptionEn: 'Main text colour used on the back of the ID card.',
                    descriptionAm: 'በካርዱ ኋላ ላይ ለጽሑፍ የሚጠቀም ዋና ቀለም።',
                    isPublic: true,
                    validationRules: ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                    sortOrder: 140,
                ),
                // Back card — return address
                'return_address_en' => self::field(
                    type: 'text',
                    default: 'Addis Ababa City Administration, Public Service & HRD Bureau',
                    labelEn: 'Return Address (English)',
                    labelAm: 'የመመለሻ አድራሻ (እንግሊዝኛ)',
                    descriptionEn: 'Return address printed on the back of the ID card (English).',
                    descriptionAm: 'በካርዱ ኋላ ላይ የሚታይ የመመለሻ አድራሻ (እንግሊዝኛ)።',
                    isPublic: true,
                    validationRules: ['required', 'string', 'max:300'],
                    sortOrder: 150,
                ),
                'return_address_am' => self::field(
                    type: 'text',
                    default: 'አዲስ አበባ ከተማ አስተዳደር፣ የሲቪል ሰርቪስና ሰው ሃብት ልማት ቢሮ',
                    labelEn: 'Return Address (Amharic)',
                    labelAm: 'የመመለሻ አድራሻ (አማርኛ)',
                    descriptionEn: 'Return address printed on the back of the ID card (Amharic).',
                    descriptionAm: 'በካርዱ ኋላ ላይ የሚታይ የመመለሻ አድራሻ (አማርኛ)።',
                    isPublic: true,
                    validationRules: ['required', 'string', 'max:300'],
                    sortOrder: 160,
                ),
                // Back card — layout options
                'show_magnetic_stripe' => self::field(
                    type: 'boolean',
                    default: true,
                    labelEn: 'Show Magnetic Stripe',
                    labelAm: 'መግነጢሳዊ ጭረት አሳይ',
                    descriptionEn: 'Display the magnetic stripe simulation on the back of the ID card.',
                    descriptionAm: 'በካርዱ ኋላ ላይ የተመስሎ መግነጢሳዊ ጭረት ያሳዩ።',
                    isPublic: true,
                    validationRules: ['required', 'boolean'],
                    sortOrder: 170,
                ),
                'qr_size' => self::field(
                    type: 'select',
                    default: '100',
                    labelEn: 'QR Code Size',
                    labelAm: 'የQR ኮድ መጠን',
                    descriptionEn: 'Pixel size of the QR code on the back of the card.',
                    descriptionAm: 'በካርዱ ኋላ ላይ የQR ኮድ ፒክሰል መጠን።',
                    isPublic: true,
                    options: ['80', '100', '120'],
                    validationRules: ['required', 'in:80,100,120'],
                    sortOrder: 180,
                ),
                'card_padding' => self::field(
                    type: 'select',
                    default: 'normal',
                    labelEn: 'Card Content Padding',
                    labelAm: 'የካርድ ይዘት ፓዲንግ',
                    descriptionEn: 'Inner spacing for the card content areas.',
                    descriptionAm: 'ለካርድ ይዘት አካባቢዎች ያለ ውስጣዊ ክፍተት።',
                    isPublic: true,
                    options: ['compact', 'normal', 'spacious'],
                    validationRules: ['required', 'in:compact,normal,spacious'],
                    sortOrder: 190,
                ),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function groups(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function group(string $group): array
    {
        return self::definitions()[$group] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function definition(string $group, string $key): ?array
    {
        return self::definitions()[$group][$key] ?? null;
    }

    public static function exists(string $group, string $key): bool
    {
        return self::definition($group, $key) !== null;
    }

    /**
     * @return array<int, string>
     */
    public static function publicShareableKeys(): array
    {
        return [
            'app.name',
            'app.short_name',
            'general.organization_name',
            'general.support_email',
            'general.support_phone',
            'general.system_environment_label',
            'general.help_center_url',
            'general.privacy_policy_url',
            'general.terms_url',
            'general.login_page_message_en',
            'general.login_page_message_am',
            'general.identity_system_logo_url',
            'general.favicon_url',
            'general.seal_url',
            'localization.default_locale',
            'localization.fallback_locale',
            'localization.supported_locales',
            'localization.timezone',
            'localization.date_format',
            'localization.datetime_format',
            'appearance.default_theme',
            'appearance.primary_color',
            'appearance.secondary_color',
            'appearance.accent_color',
            'appearance.table_density',
            'appearance.button_style',
            'appearance.card_radius',
            'appearance.allow_user_theme_switching',
            'appearance.sidebar_compact_default',
            'appearance.show_language_switcher',
            'appearance.enable_ui_animations',
            'appearance.default_page_size',
            'security.maintenance_banner_enabled',
            'security.maintenance_banner_message_en',
            'security.maintenance_banner_message_am',
            // ID Cards
            'id_cards.template',
            'id_cards.front_bg_from',
            'id_cards.front_bg_to',
            'id_cards.front_text_primary',
            'id_cards.front_text_secondary',
            'id_cards.front_name_font_size',
            'id_cards.front_label_font_size',
            'id_cards.city_name_en',
            'id_cards.city_name_am',
            'id_cards.bureau_name_en',
            'id_cards.bureau_name_am',
            'id_cards.show_organization_logo',
            'id_cards.show_photo',
            'id_cards.show_full_name_en',
            'id_cards.show_full_name_am',
            'id_cards.show_employee_number',
            'id_cards.show_card_number',
            'id_cards.show_organization',
            'id_cards.show_organization_unit',
            'id_cards.show_position',
            'id_cards.show_job_grade',
            'id_cards.show_employment_status',
            'id_cards.show_issue_date',
            'id_cards.show_expiry_date',
            'id_cards.show_signature',
            'id_cards.show_qr',
            'id_cards.show_return_notice',
            'id_cards.show_emergency_contact',
            'id_cards.back_bg_from',
            'id_cards.back_bg_to',
            'id_cards.back_text_color',
            'id_cards.return_address_en',
            'id_cards.return_address_am',
            'id_cards.show_magnetic_stripe',
            'id_cards.qr_size',
            'id_cards.card_padding',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function secretKeys(): array
    {
        $secretKeys = [];

        foreach (self::definitions() as $group => $fields) {
            foreach ($fields as $key => $definition) {
                if (($definition['is_encrypted'] ?? false) === true) {
                    $secretKeys[] = "{$group}.{$key}";
                }
            }
        }

        return $secretKeys;
    }

    /**
     * @return array<string, mixed>
     */
    private static function field(
        string $type,
        mixed $default,
        string $labelEn,
        string $labelAm,
        ?string $descriptionEn = null,
        ?string $descriptionAm = null,
        bool $isPublic = false,
        bool $isEncrypted = false,
        bool $isRequired = false,
        ?array $options = null,
        array $validationRules = [],
        int $sortOrder = 0,
    ): array {
        return [
            'type' => $type,
            'default' => $default,
            'label_en' => $labelEn,
            'label_am' => $labelAm,
            'description_en' => $descriptionEn,
            'description_am' => $descriptionAm,
            'is_public' => $isPublic,
            'is_encrypted' => $isEncrypted,
            'is_required' => $isRequired,
            'options' => $options,
            'validation_rules' => $validationRules,
            'sort_order' => $sortOrder,
        ];
    }
}
