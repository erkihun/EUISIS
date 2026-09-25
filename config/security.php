<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Public self-service registration
    |--------------------------------------------------------------------------
    |
    | When false, the /register route is replaced by a redirect to /login and
    | no new accounts can be created from the public Internet. Administrators
    | create accounts through the Users admin module instead.
    |
    */

    'registration_enabled' => (bool) env('REGISTRATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | MFA: required roles
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of role names (exact match against spatie/permission
    | role names) whose users must enrol a TOTP authenticator and pass a
    | challenge on every fresh session.
    |
    */

    'mfa_required_roles' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MFA_REQUIRED_ROLES', 'Super Admin,City Admin'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | MFA: privileged roles (legacy mapping)
    |--------------------------------------------------------------------------
    |
    | Role names treated as "privileged/admin" when the legacy
    | security.require_mfa_for_admins system setting is still true. Used only
    | for backward compatibility until administrators save the new role-based
    | MFA settings (security.mfa_required_role_ids).
    |
    */

    'mfa_privileged_roles' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MFA_PRIVILEGED_ROLES', 'Super Admin,City Admin,Organization Admin'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | MFA: enforcement switch
    |--------------------------------------------------------------------------
    |
    | Master kill-switch for the RequireMfa middleware. Useful when running
    | the automated test suite (which uses factory users without enrolled
    | authenticators) or when temporarily disabling MFA during incident
    | response. Defaults ON in production-like envs, OFF in testing.
    |
    */

    'mfa_enforce' => (bool) env('MFA_ENFORCE', env('APP_ENV') !== 'testing'),

    /*
    |--------------------------------------------------------------------------
    | MFA: challenge session lifetime (minutes)
    |--------------------------------------------------------------------------
    |
    | How long an MFA verification remains valid within the current session
    | before the user is re-challenged. Defaults to the session lifetime.
    |
    */

    'mfa_session_lifetime_minutes' => (int) env('MFA_SESSION_LIFETIME_MINUTES', (int) env('SESSION_LIFETIME', 120)),

    /*
    |--------------------------------------------------------------------------
    | MFA: recovery code count
    |--------------------------------------------------------------------------
    */

    'mfa_recovery_code_count' => 8,

    /*
    |--------------------------------------------------------------------------
    | ID card: QR payload format version
    |--------------------------------------------------------------------------
    |
    | Records which payload shape a card's QR was issued under. Version 1 is
    | the OTP-gated checker link, config('app.url')."/id-checker/{card_uuid}".
    |
    | This is metadata only. Raising it marks newly issued cards as carrying the
    | new format; it never rewrites an existing card, because the printed QR on
    | a physical card cannot change. public_card_uuid, the card token and the
    | card number are untouched by this value.
    |
    */

    'id_card_qr_payload_version' => (int) env('ID_CARD_QR_PAYLOAD_VERSION', 1),

    /*
    |--------------------------------------------------------------------------
    | Paid external services: application-side spend caps
    |--------------------------------------------------------------------------
    |
    | Enforced by ExternalUsageBudgetService for every paid call. Rate limiters
    | cap one caller; these cap the total, so rotating IPs across many cards
    | cannot run up an unbounded bill. 0 disables a cap. `enabled` is the kill
    | switch. Also set a hard spending limit at the provider account where the
    | provider supports one: an application cap alone is not enough.
    |
    */

    'external_usage' => [
        'sms' => [
            'enabled' => (bool) env('SMS_ENABLED', true),
            'daily_cap' => (int) env('SMS_DAILY_CAP', 500),
            'monthly_cap' => (int) env('SMS_MONTHLY_CAP', 10000),
            'per_recipient_daily_cap' => (int) env('SMS_PER_RECIPIENT_DAILY_CAP', 8),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Session idle timeout
    |--------------------------------------------------------------------------
    |
    | The authoritative idle timeout is System Settings -> Security -> Session
    | Timeout Minutes; AppServiceProvider copies it into `idle_timeout_minutes`
    | on every boot. The value here is only the fallback used when settings
    | cannot be read.
    |
    | Laravel's own storage lifetime (`session.lifetime`) is derived from it
    | as idle timeout + `storage_grace_minutes`, never configured separately,
    | so storage always outlives the idle policy and the application can end
    | an idle session cleanly (with its message) instead of Laravel dropping
    | it first. See docs/session-management.md.
    |
    | `passive_routes` never count as user activity, whatever the client
    | sends: background polling must not keep an idle session alive.
    |
    */

    'session' => [
        'idle_timeout_minutes' => (int) env('SESSION_LIFETIME', 120),
        'storage_grace_minutes' => (int) env('SESSION_STORAGE_GRACE_MINUTES', 30),
        'passive_routes' => [
            'session.status',
            'notifications.feed',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password policy (docs/password-security-policy.md)
    |--------------------------------------------------------------------------
    |
    | NIST SP 800-63B-4 aligned. System Settings -> Security may RAISE the
    | minimum length and tune the options; it can never go below these floors
    | without a code change (an explicit security decision).
    |
    | `context_terms` are predictable, service-specific words: a password made
    | only of these (plus digits and symbols) is rejected. The larger list of
    | common passwords lives in `blocklist_path`.
    |
    | `breach_check.enabled` is the infrastructure master switch (e.g. an
    | air-gapped deployment); the System Setting toggles it per installation.
    | Only the first five hex characters of the SHA-1 digest ever leave the
    | server (k-anonymity range query).
    |
    */

    // Failed sign-ins from one IP across all accounts (password spraying),
    // per Lockout Minutes window. See App\Security\LoginThrottle.
    'login' => [
        'per_ip_failures' => (int) env('LOGIN_PER_IP_FAILURES', 100),
    ],

    'passwords' => [
        'minimum_length_floor' => 15,
        'maximum_length_floor' => 64,
        'maximum_length_ceiling' => 128,
        'history_max' => 24,
        'personal_token_min_length' => (int) env('PASSWORD_PERSONAL_TOKEN_MIN_LENGTH', 4),
        'blocklist_path' => resource_path('security/common-passwords.txt'),
        'context_terms' => [
            'euisis', 'addis', 'ababa', 'addisababa', 'aacity', 'ethiopia', 'habesha', 'bureau',
            'employee', 'admin', 'administrator', 'password', 'passw0rd', 'welcome', 'changeme',
            'change', 'letmein', 'qwerty', 'default', 'temporary', 'temp', 'secret', 'login',
            'user', 'test', 'guest', 'root', 'provider', 'cafeteria', 'transport', 'selam',
        ],
        'breach_check' => [
            'enabled' => (bool) env('PASSWORD_BREACH_CHECK', true),
            'endpoint' => env('PASSWORD_BREACH_CHECK_ENDPOINT', 'https://api.pwnedpasswords.com/range/'),
            'timeout_seconds' => (int) env('PASSWORD_BREACH_CHECK_TIMEOUT', 3),
            // When the service cannot be reached: refuse the change for
            // privileged accounts, accept (with a warning log) for others.
            'fail_closed_for_privileged' => true,
        ],
    ],
];
