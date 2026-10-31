<?php

declare(strict_types=1);

namespace Modules\Court\Http\Support\Ui;

use Modules\Court\Domain\Models\Role;
use Modules\Court\Domain\Models\SystemSetting;
use Illuminate\Http\Request;

/**
 * court-ms's system settings in the shape of the EUISIS settings page
 * (SystemSettings/Index, copied from EUISIS): sections of typed fields. A
 * field carries the EUISIS key when EUISIS has the same setting, so it lands
 * in the same card under the same heading; court-ms's own settings keep their
 * column name and appear in the section's "Other" card. Values still live in
 * court-ms's single system_settings row.
 *
 * Each field: [key, column, type, rules, label key in lang/{en,am}/settings.php, options].
 * Types are the EUISIS ones: string, text, email, url, phone, integer, boolean,
 * select, color, timezone, password (a write-only secret), image and json.
 */
final class CourtSettingsSchema
{
    /** @var array<string, list<array{0: string, 1: ?string, 2: string, 3: list<string>, 4: string, 5?: list<string>, 6?: string}>> */
    private const SECTIONS = [
        'general' => [
            ['application_name', 'app_name', 'string', ['required', 'string', 'max:255'], 'app_name'],
            ['application_short_name', 'short_name', 'string', ['nullable', 'string', 'max:50'], 'short_name'],
            ['identity_system_logo', 'logo_path', 'image', ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'], 'logo', [], 'logos'],
            ['favicon', 'favicon_path', 'image', ['nullable', 'file', 'mimes:png,ico', 'max:512'], 'favicon', [], 'favicons'],
            ['seal', 'seal_path', 'image', ['nullable', 'image', 'mimes:png', 'max:1024'], 'official_seal', [], 'seals'],
            ['support_email', 'contact_email', 'email', ['nullable', 'email', 'max:255'], 'contact_email'],
            ['support_phone', 'contact_phone', 'phone', ['nullable', 'string', 'max:50'], 'contact_phone'],
            ['welcome_message', 'welcome_message', 'text', ['nullable', 'string', 'max:500'], 'welcome_message'],
            ['about', 'about', 'text', ['nullable', 'string'], 'about'],
            ['footer_text', 'footer_text', 'text', ['nullable', 'string', 'max:500'], 'footer_text'],
            ['address', 'address', 'string', ['nullable', 'string', 'max:255'], 'address'],
            ['website_url', 'website_url', 'url', ['nullable', 'url', 'max:255'], 'website_url'],
            ['login_banner', 'banner_path', 'image', ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:3072'], 'header_banner', [], 'banners'],
            ['show_banner_on_login', 'show_banner_on_login', 'boolean', ['nullable', 'boolean'], 'show_banner_login'],
        ],
        'localization' => [
            ['default_locale', 'default_locale', 'select', ['nullable', 'string', 'in:en,am'], 'default_language', ['en', 'am']],
            ['timezone', 'timezone', 'timezone', ['nullable', 'string', 'timezone'], 'timezone'],
            ['date_format', 'date_format', 'select', ['nullable', 'string', 'in:Y-m-d,d/m/Y,m/d/Y,d-m-Y,F j, Y'], 'date_format', ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'F j, Y']],
            ['time_format', 'time_format', 'select', ['nullable', 'string', 'in:H:i,h:i A'], 'time_format', ['H:i', 'h:i A']],
            ['use_ethiopian_calendar', 'use_ethiopian_calendar', 'boolean', ['nullable', 'boolean'], 'ethiopian_calendar'],
        ],
        // The EUISIS appearance settings the copied shell reads (EUISIS SystemSettingsRegistry, group appearance).
        'appearance' => [
            ['primary_color', 'primary_color', 'color', ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'primary_color'],
            ['secondary_color', 'secondary_color', 'color', ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'secondary_color'],
            ['accent_color', 'accent_color', 'color', ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'accent_color'],
            ['sidebar_color', 'sidebar_color', 'color', ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'sidebar_color'],
            ['default_theme', 'default_theme', 'select', ['required', 'string', 'in:light,dark,system'], 'default_theme', ['light', 'dark', 'system']],
            ['allow_user_theme_switching', 'allow_user_theme_switching', 'boolean', ['nullable', 'boolean'], 'allow_user_theme_switching'],
            ['enable_ui_animations', 'enable_ui_animations', 'boolean', ['nullable', 'boolean'], 'enable_ui_animations'],
            ['table_density', 'table_density', 'select', ['required', 'string', 'in:compact,comfortable,spacious'], 'table_density', ['compact', 'comfortable', 'spacious']],
            ['button_style', 'button_style', 'select', ['required', 'string', 'in:rounded,soft,square'], 'button_style', ['rounded', 'soft', 'square']],
            ['card_radius', 'card_radius', 'select', ['required', 'string', 'in:sm,md,lg,xl,2xl'], 'card_radius', ['sm', 'md', 'lg', 'xl', '2xl']],
            ['logo_position', 'logo_position', 'select', ['required', 'string', 'in:start,center'], 'logo_position', ['start', 'center']],
            ['sidebar_compact_default', 'sidebar_compact_default', 'boolean', ['nullable', 'boolean'], 'sidebar_compact_default'],
            ['show_breadcrumbs', 'show_breadcrumbs', 'boolean', ['nullable', 'boolean'], 'show_breadcrumbs'],
            ['show_language_switcher', 'show_language_switcher', 'boolean', ['nullable', 'boolean'], 'show_lang_switcher'],
            ['sticky_table_headers', 'sticky_table_headers', 'boolean', ['nullable', 'boolean'], 'sticky_table_headers'],
        ],
        'notifications' => [
            ['email_notifications_enabled', 'mail_enabled', 'boolean', ['nullable', 'boolean'], 'email_smtp'],
            ['sms_notifications_enabled', 'sms_enabled', 'boolean', ['nullable', 'boolean'], 'sms_gateway'],
            ['telegram_notifications_enabled', 'telegram_enabled', 'boolean', ['nullable', 'boolean'], 'enable_telegram'],
        ],
        'email' => [
            ['mail_mailer', 'mail_mailer', 'select', ['nullable', 'string', 'in:smtp,sendmail,log'], 'mailer_driver', ['smtp', 'sendmail', 'log']],
            ['mail_host', 'mail_host', 'string', ['nullable', 'string', 'max:255'], 'smtp_host'],
            ['mail_port', 'mail_port', 'integer', ['nullable', 'integer', 'min:1', 'max:65535'], 'smtp_port'],
            ['mail_encryption', 'mail_encryption', 'select', ['nullable', 'string', 'in:tls,ssl,starttls,none'], 'encryption', ['tls', 'ssl', 'starttls', 'none']],
            ['mail_username', 'mail_username', 'string', ['nullable', 'string', 'max:255'], 'mail_username'],
            ['mail_password', 'mail_password', 'password', ['nullable', 'string', 'max:500'], 'mail_password'],
            ['mail_from_address', 'mail_from_address', 'email', ['nullable', 'email', 'max:255'], 'from_address'],
            ['mail_from_name', 'mail_from_name', 'string', ['nullable', 'string', 'max:255'], 'from_name'],
        ],
        'sms' => [
            ['sms_provider', 'sms_provider', 'string', ['nullable', 'string', 'max:100'], 'sms_provider'],
            ['sms_api_url', 'sms_base_url', 'url', ['nullable', 'url', 'max:255'], 'sms_base_url'],
            ['sms_api_key', 'sms_api_key', 'password', ['nullable', 'string', 'max:500'], 'sms_api_key'],
            ['sms_sender_id', 'sms_sender_id', 'string', ['nullable', 'string', 'max:20'], 'sms_sender_id'],
            ['sms_api_secret', 'sms_api_secret', 'password', ['nullable', 'string', 'max:500'], 'sms_api_secret'],
        ],
        'telegram' => [
            ['telegram_bot_token', 'telegram_bot_token', 'password', ['nullable', 'string', 'max:500'], 'bot_token'],
            ['telegram_default_chat_id', 'telegram_default_chat_id', 'string', ['nullable', 'string', 'max:100'], 'default_chat_id'],
        ],
        'security' => [
            ['password_min_length', 'password_min_length', 'integer', ['nullable', 'integer', 'min:6', 'max:128'], 'password_min_length'],
            ['password_require_uppercase', 'password_require_uppercase', 'boolean', ['nullable', 'boolean'], 'require_uppercase'],
            ['password_require_number', 'password_require_number', 'boolean', ['nullable', 'boolean'], 'require_number'],
            ['password_require_symbol', 'password_require_symbol', 'boolean', ['nullable', 'boolean'], 'require_symbol'],
            ['session_timeout_minutes', 'session_lifetime', 'integer', ['nullable', 'integer', 'min:5', 'max:43200'], 'session_lifetime'],
            ['max_login_attempts', 'login_max_attempts', 'integer', ['nullable', 'integer', 'min:1', 'max:100'], 'max_login_attempts'],
            ['lockout_minutes', 'lockout_minutes', 'integer', ['nullable', 'integer', 'min:1', 'max:1440'], 'lockout_duration'],
            ['force_https', 'force_https', 'boolean', ['nullable', 'boolean'], 'force_https'],
            ['mfa_enabled', 'mfa_enabled', 'boolean', ['nullable', 'boolean'], 'mfa_enabled'],
            // Stored on the roles (roles.mfa_required), not in system_settings.
            ['mfa_required_role_ids', null, 'json', ['nullable', 'array'], 'mfa_role_policy'],
            ['audit_retention_days', 'audit_retention_days', 'integer', ['nullable', 'integer', 'min:1', 'max:3650'], 'audit_retention_days'],
            ['api_rate_limit_per_minute', 'api_rate_limit', 'integer', ['nullable', 'integer', 'min:1', 'max:10000'], 'rate_limit'],
            ['session_warning_minutes', 'session_warning_minutes', 'integer', ['nullable', 'integer', 'min:1', 'max:120'], 'session_warning_minutes'],
            ['session_extend_on_activity', 'session_extend_on_activity', 'boolean', ['nullable', 'boolean'], 'session_extend_on_activity'],
            ['audit_logging_enabled', 'audit_logging_enabled', 'boolean', ['nullable', 'boolean'], 'audit_logging_enabled'],
            ['api_enabled', 'api_enabled', 'boolean', ['nullable', 'boolean'], 'enable_api'],
            ['maintenance_mode', 'maintenance_mode', 'boolean', ['nullable', 'boolean'], 'maintenance_mode'],
            ['registration_open', 'registration_open', 'boolean', ['nullable', 'boolean'], 'registration_open'],
        ],
    ];

    /**
     * EUISIS's appearance defaults (SystemSettingsRegistry), used while court-ms has
     * no value of its own: the page shows them and the shell applies them.
     *
     * @var array<string, string|bool>
     */
    public const APPEARANCE_DEFAULTS = [
        'primary_color' => '#122170',
        'secondary_color' => '#1D3084',
        'accent_color' => '#D12908',
        'sidebar_color' => '#FFFFFF',
        'default_theme' => 'system',
        'allow_user_theme_switching' => true,
        'enable_ui_animations' => true,
        'table_density' => 'comfortable',
        'button_style' => 'rounded',
        'card_radius' => 'xl',
        'logo_position' => 'start',
        // Staff screens open with the side menu collapsed (2026-10-29); each person's own toggle is kept in their browser.
        'sidebar_compact_default' => true,
        'show_breadcrumbs' => true,
        'show_language_switcher' => true,
        'sticky_table_headers' => true,
    ];

    /** @return list<string> */
    public static function sections(): array
    {
        return array_keys(self::SECTIONS);
    }

    /**
     * The page's `settingGroups`: every section with its fields in the EUISIS SettingsField shape.
     *
     * @return array<string, array{fields: list<array<string, mixed>>, can_manage: bool}>
     */
    public static function groups(SystemSetting $settings, bool $canManage): array
    {
        $mfaRoleIds = Role::query()->where('mfa_required', true)->pluck('id')->map(fn ($id): string => (string) $id)->values()->all();
        $groups = [];
        foreach (self::SECTIONS as $section => $fields) {
            $groups[$section] = [
                'fields' => array_values(array_map(fn (array $field, int $order): array => self::payload($section, $field, $order, $settings, $mfaRoleIds), $fields, array_keys($fields))),
                'can_manage' => $canManage,
            ];
        }

        return $groups;
    }

    /** @return array<string, mixed> */
    public static function rules(string $section): array
    {
        $rules = [];
        foreach (self::SECTIONS[$section] as $field) {
            $rules[$field[0]] = $field[3];
            if ($field[2] === 'json') {
                $rules[$field[0].'.*'] = ['integer', 'exists:court_roles,id'];
            }
        }

        return [
            ...$rules,
            // The EUISIS page sends these lists: secrets to remove, images to remove.
            'clear' => ['nullable', 'array'],
            'clear.*' => ['string'],
            'remove_assets' => ['nullable', 'array'],
            'remove_assets.*' => ['string'],
        ];
    }

    /**
     * Saves one section from a validated request. A blank secret keeps the stored one
     * (never sent to the page); `clear[]` removes it. A new image replaces the stored
     * one; `remove_assets[]` removes it.
     *
     * @param  array<string, mixed>  $data
     */
    public static function apply(string $section, array $data, Request $request, SystemSetting $settings): void
    {
        $clear = (array) ($data['clear'] ?? []);
        $removeAssets = (array) ($data['remove_assets'] ?? []);

        foreach (self::SECTIONS[$section] as $field) {
            [$key, $column, $type] = $field;

            if ($type === 'json') {
                $ids = collect($data[$key] ?? [])->map(fn ($id): int => (int) $id)->all();
                Role::query()->update(['mfa_required' => false]);
                Role::query()->whereKey($ids)->update(['mfa_required' => true]);

                continue;
            }

            match ($type) {
                'password' => filled($data[$key] ?? null)
                    ? $settings->{$column} = $data[$key]
                    : (in_array($key, $clear, true) ? $settings->{$column} = null : null),
                'image' => $request->hasFile($key)
                    ? $settings->{$column} = $request->file($key)->store($field[6] ?? 'settings', 'court_public')
                    : (in_array($key, $removeAssets, true) ? $settings->{$column} = null : null),
                'boolean' => $settings->{$column} = $request->boolean($key),
                default => $settings->{$column} = $data[$key] ?? null,
            };
        }
    }

    /**
     * @param  array{0: string, 1: ?string, 2: string, 3: list<string>, 4: string, 5?: list<string>, 6?: string}  $field
     * @param  list<string>  $mfaRoleIds
     * @return array<string, mixed>
     */
    private static function payload(string $section, array $field, int $order, SystemSetting $settings, array $mfaRoleIds): array
    {
        [$key, $column, $type, $rules, $labelKey] = $field;
        $default = $section === 'appearance' ? (self::APPEARANCE_DEFAULTS[$key] ?? null) : null;
        $stored = $column !== null ? ($settings->{$column} ?? $default) : null;
        $isSecret = $type === 'password';

        $value = match ($type) {
            // A secret only says whether it is set; an image sends its address instead.
            'password', 'image' => null,
            'boolean' => (bool) $stored,
            'integer' => $stored !== null ? (int) $stored : null,
            'json' => $mfaRoleIds,
            default => $stored,
        };

        return [
            'key' => $key,
            'group' => $section,
            'type' => $type,
            'value' => $value,
            'is_encrypted' => $isSecret,
            'is_public' => false,
            'configured' => $isSecret || $type === 'image' ? filled($stored) : $stored !== null && $stored !== '',
            'options' => $field[5] ?? null,
            'label_en' => self::text($labelKey, 'en'),
            'label_am' => self::text($labelKey, 'am'),
            'description_en' => self::description($labelKey, 'en'),
            'description_am' => self::description($labelKey, 'am'),
            'sort_order' => $order,
            'default' => $default,
            'validation_rules' => $rules,
            'is_required' => in_array('required', $rules, true),
            'asset_url' => $type === 'image' && filled($stored) ? asset('storage/'.$stored) : null,
        ];
    }

    private static function text(string $key, string $locale): string
    {
        return (string) __('court::settings.'.$key, [], $locale);
    }

    /** The field's `_desc` or `_hint` text, when the settings translations have one. */
    private static function description(string $key, string $locale): ?string
    {
        foreach (['_desc', '_hint'] as $suffix) {
            if (trans()->hasForLocale('settings.'.$key.$suffix, $locale)) {
                return (string) __('court::settings.'.$key.$suffix, [], $locale);
            }
        }

        return null;
    }
}
