<?php

namespace Modules\Court\Domain\Models;

use Modules\Court\Domain\Casts\EncryptedOrPlain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\Court\Domain\Models\Concerns\Auditable;
use Modules\Court\Domain\Models\Concerns\AuditableContract as AuditableContract;

class SystemSetting extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'court_system_settings';

    protected $fillable = [
        // Identity
        'app_name', 'short_name', 'welcome_message', 'about', 'footer_text',
        'address', 'website_url', 'contact_email', 'contact_phone',

        // Branding assets
        'logo_path', 'banner_path', 'favicon_path', 'seal_path',

        // Localization
        'default_locale', 'show_language_switcher', 'timezone', 'date_format', 'time_format', 'use_ethiopian_calendar',

        // Security
        'maintenance_mode', 'registration_open', 'force_https',
        'mfa_enabled',
        'audit_logging_enabled', 'audit_retention_days',
        'session_lifetime', 'password_min_length',
        'session_warning_minutes', 'session_extend_on_activity',
        'password_require_uppercase', 'password_require_number', 'password_require_symbol',
        'login_max_attempts', 'lockout_minutes',

        // Appearance
        'accent_palette', 'default_theme', 'show_banner_on_login', 'custom_css',
        'primary_color', 'secondary_color', 'accent_color', 'sidebar_color',
        'table_density', 'button_style', 'card_radius', 'logo_position',
        'allow_user_theme_switching', 'enable_ui_animations', 'sidebar_compact_default',
        'show_breadcrumbs', 'sticky_table_headers',

        // API
        'api_enabled', 'api_rate_limit',

        // Email (SMTP)
        'mail_enabled', 'mail_mailer', 'mail_host', 'mail_port',
        'mail_username', 'mail_password', 'mail_encryption',
        'mail_from_address', 'mail_from_name',

        // Telegram
        'telegram_enabled', 'telegram_bot_token', 'telegram_default_chat_id',

        // SMS
        'sms_enabled', 'sms_provider', 'sms_api_key', 'sms_api_secret',
        'sms_sender_id', 'sms_base_url',
    ];

    protected $casts = [
        'maintenance_mode' => 'boolean',
        'registration_open' => 'boolean',
        'force_https' => 'boolean',
        'mfa_enabled' => 'boolean',
        'audit_logging_enabled' => 'boolean',
        'session_extend_on_activity' => 'boolean',
        'use_ethiopian_calendar' => 'boolean',
        'show_language_switcher' => 'boolean',
        'password_require_uppercase' => 'boolean',
        'password_require_number' => 'boolean',
        'password_require_symbol' => 'boolean',
        'show_banner_on_login' => 'boolean',
        'allow_user_theme_switching' => 'boolean',
        'enable_ui_animations' => 'boolean',
        'sidebar_compact_default' => 'boolean',
        'show_breadcrumbs' => 'boolean',
        'sticky_table_headers' => 'boolean',
        'api_enabled' => 'boolean',
        'mail_enabled' => 'boolean',
        'mail_port' => 'integer',
        'mail_password' => EncryptedOrPlain::class,
        'telegram_enabled' => 'boolean',
        'sms_enabled' => 'boolean',
        'session_lifetime' => 'integer',
        'session_warning_minutes' => 'integer',
        'audit_retention_days' => 'integer',
        'password_min_length' => 'integer',
        'login_max_attempts' => 'integer',
        'lockout_minutes' => 'integer',
        'api_rate_limit' => 'integer',
    ];

    /**
     * The settings row from the shared 'system_settings' cache key.
     *
     * Every consumer of that key MUST go through this accessor: the key holds
     * the model instance, and caching anything else under it breaks all other
     * readers. Self-heals if a stale scalar is found in the cache.
     */
    public static function cached(): ?self
    {
        $cached = Cache::remember('system_settings', 3600, fn () => static::query()->first());

        if ($cached !== null && ! $cached instanceof self) {
            Cache::forget('system_settings');
            $cached = Cache::remember('system_settings', 3600, fn () => static::query()->first());
        }

        return $cached instanceof self ? $cached : null;
    }

    /** Fetch the single settings row, or return a new instance with sane defaults. */
    public static function current(): self
    {
        return static::first() ?? new static([
            'app_name' => config('app.name', 'Court MS'),
            'short_name' => 'CMS',
            'default_locale' => config('app.locale', 'en'),
            'show_language_switcher' => true,
            'timezone' => config('app.timezone', 'Africa/Addis_Ababa'),
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
            'use_ethiopian_calendar' => false,
            'maintenance_mode' => false,
            'registration_open' => true,
            'force_https' => false,
            'mfa_enabled' => false,
            'session_lifetime' => 120,
            'session_warning_minutes' => 5,
            'session_extend_on_activity' => true,
            'audit_logging_enabled' => true,
            'audit_retention_days' => 365,
            'password_min_length' => 8,
            'password_require_uppercase' => true,
            'password_require_number' => true,
            'password_require_symbol' => false,
            'login_max_attempts' => 5,
            'lockout_minutes' => 15,
            'accent_palette' => 'blue',
            'default_theme' => 'system',
            'show_banner_on_login' => true,
            'api_enabled' => false,
            'api_rate_limit' => 60,
            'mail_enabled' => false,
            'mail_mailer' => 'smtp',
            'mail_port' => 587,
            'mail_encryption' => 'tls',
            'telegram_enabled' => false,
            'sms_enabled' => false,
        ]);
    }
}
