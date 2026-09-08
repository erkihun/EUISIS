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

];
