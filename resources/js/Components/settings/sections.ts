import type { SettingsField } from '@/lib/settings';

type Match = string[] | ((field: SettingsField) => boolean);

/** A titled card of fields within a settings section. Titles: settings.sections.<section>.<key>(Help). */
type CardDefinition = { key: string; fields: Match };

const ID_CARD_BACK = ['show_return_notice', 'show_emergency_contact', 'show_signature', 'show_magnetic_stripe'];
const ID_CARD_VERIFICATION = ['show_qr', 'qr_size', 'verification_url'];

const CARDS: Record<string, CardDefinition[]> = {
    general: [
        { key: 'identity', fields: ['application_name', 'application_short_name', 'organization_name', 'system_environment_label', 'default_dashboard_route'] },
        { key: 'branding', fields: ['identity_system_logo', 'favicon', 'seal'] },
        { key: 'support', fields: ['support_email', 'support_phone', 'help_center_url', 'privacy_policy_url', 'terms_url'] },
        { key: 'login', fields: ['login_page_message_en', 'login_page_message_am'] },
    ],
    localization: [
        { key: 'language', fields: ['default_locale', 'fallback_locale', 'supported_locales'] },
        { key: 'dateTime', fields: ['timezone', 'calendar_system_mode', 'date_format', 'datetime_format', 'first_day_of_week'] },
        { key: 'display', fields: ['number_format', 'organization_name_display', 'employee_name_display'] },
    ],
    notifications: [
        { key: 'channels', fields: ['database_notifications_enabled', 'email_notifications_enabled', 'sms_notifications_enabled', 'telegram_notifications_enabled'] },
        { key: 'events', fields: (field) => field.key.startsWith('notify_') },
        { key: 'delivery', fields: ['notification_retry_attempts', 'notification_queue_name'] },
    ],
    email: [
        { key: 'transport', fields: ['mail_mailer', 'mail_host', 'mail_port', 'mail_encryption'] },
        { key: 'credentials', fields: ['mail_username', 'mail_password'] },
        { key: 'sender', fields: ['mail_from_address', 'mail_from_name'] },
        { key: 'delivery', fields: ['email_queue_enabled', 'email_rate_limit_per_minute', 'email_test_recipient'] },
    ],
    sms: [
        { key: 'provider', fields: ['sms_provider', 'sms_api_url', 'sms_api_key'] },
        { key: 'sender', fields: ['sms_sender_id', 'sms_default_country_code'] },
        { key: 'delivery', fields: ['sms_timeout_seconds', 'sms_rate_limit_per_minute', 'sms_test_phone'] },
    ],
    telegram: [
        { key: 'bot', fields: ['telegram_bot_token', 'telegram_webhook_url'] },
        { key: 'chats', fields: ['telegram_default_chat_id', 'telegram_notifications_channel', 'telegram_test_chat_id'] },
        { key: 'delivery', fields: ['telegram_timeout_seconds'] },
    ],
    security: [
        { key: 'passwords', fields: (field) => field.key.startsWith('password_') },
        { key: 'signIn', fields: ['session_timeout_minutes', 'max_login_attempts', 'lockout_minutes', 'force_https'] },
        { key: 'mfa', fields: ['mfa_enabled', 'mfa_required_for_all', 'mfa_required_role_ids'] },
        { key: 'uploads', fields: ['max_upload_size_mb', 'allowed_file_types', 'allowed_upload_mime_types'] },
        { key: 'maintenance', fields: (field) => field.key.startsWith('maintenance_banner_') },
        { key: 'protection', fields: ['audit_retention_days', 'sensitive_export_requires_reason', 'api_rate_limit_per_minute', 'verification_rate_limit_per_minute'] },
    ],
    appearance: [
        { key: 'colors', fields: ['primary_color', 'secondary_color', 'accent_color', 'sidebar_color'] },
        { key: 'theme', fields: ['default_theme', 'allow_user_theme_switching', 'enable_ui_animations'] },
        { key: 'layout', fields: ['table_density', 'button_style', 'card_radius', 'logo_position', 'sidebar_compact_default', 'show_breadcrumbs', 'show_language_switcher'] },
        { key: 'dashboard', fields: ['dashboard_layout', 'dashboard_refresh_seconds', 'default_page_size', 'sticky_table_headers'] },
    ],
    // Global defaults only: template artwork and overrides are edited in ID Card Templates.
    id_cards: [
        { key: 'headerDefaults', fields: (field) => /^(city|bureau)_name_/.test(field.key) },
        { key: 'visibility', fields: (field) => field.type === 'boolean' && ![...ID_CARD_BACK, ...ID_CARD_VERIFICATION].includes(field.key) },
        { key: 'backContent', fields: (field) => /^(return_address|back_notice)_/.test(field.key) || ID_CARD_BACK.includes(field.key) },
        { key: 'verification', fields: ID_CARD_VERIFICATION },
    ],
};

export type FieldCard = { key: string; fields: SettingsField[] };

/**
 * Splits a section's fields into its cards, in registry order within each card.
 * Fields no card claims are collected in a trailing "other" card, so a setting
 * added to the registry is never hidden.
 */
export function cardsFor(section: string, fields: SettingsField[]): FieldCard[] {
    const claimed = new Set<string>();
    const cards = (CARDS[section] ?? []).map(({ key, fields: match }) => {
        const matches = fields.filter((field) => !claimed.has(field.key) && (Array.isArray(match) ? match.includes(field.key) : match(field)));
        matches.forEach((field) => claimed.add(field.key));
        return { key, fields: matches };
    });
    const rest = fields.filter((field) => !claimed.has(field.key));
    return [...cards, { key: 'other', fields: rest }].filter((card) => card.fields.length > 0);
}
