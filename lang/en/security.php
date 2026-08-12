<?php

declare(strict_types=1);

return [
    'session_expired' => 'Your session has expired. Please log in again.',
    'access_denied' => 'Access denied.',
    'scope_denied' => 'Your organization scope does not include this record.',
    'too_many_attempts' => 'Too many login attempts. Please wait :seconds seconds.',
    'strong_password_required' => 'Password must meet minimum security requirements.',

    // Registration gating
    'registration_disabled' => 'Registration is disabled. User accounts are created by administrators.',

    // Multi-Factor Authentication (TOTP)
    'mfa_required' => 'Multi-factor authentication is required for your account.',
    'mfa_challenge_failed' => 'Invalid authentication code.',
    'mfa_enabled' => 'Multi-factor authentication enabled.',
    'mfa_disabled' => 'Multi-factor authentication disabled.',
    'mfa_already_enabled' => 'Multi-factor authentication is already enabled.',
    'mfa_disable_not_allowed' => 'Multi-factor authentication cannot be disabled for your role.',
    'mfa_setup_required' => 'Please complete multi-factor authentication setup to continue.',
    'mfa_challenge_required' => 'Please verify your authentication code to continue.',
    'mfa_recovery_codes_note' => 'Save these recovery codes in a safe place. Each code can only be used once.',
    'mfa_required_for_role' => 'MFA is required for your role.',
    'mfa_settings_updated' => 'MFA settings updated.',

    // INSA-required security messages
    'upload_rejected' => 'File upload rejected.',
    'unsafe_file_type' => 'The file type is not permitted. Only allowed types may be uploaded.',
    'rate_limit_exceeded' => 'Rate limit exceeded. Please wait and try again.',
    'sensitive_info_protected' => 'Sensitive information is protected and cannot be disclosed.',
    'org_scope_denied' => 'Your organization scope does not include this record.',
    'action_not_permitted' => 'This action is not permitted for your account.',
    'security_headers_enabled' => 'Security headers are enabled.',
    'secure_config_required' => 'A secure configuration is required for this environment.',
    'invalid_request' => 'Invalid request. Please check your input and try again.',
    'unauthorized' => 'You must be signed in to access this resource.',
    'forbidden' => 'You do not have permission to perform this action.',
    'security_policy' => 'Security Policy',
    'pii_view_denied' => 'You do not have permission to view sensitive personal information.',
    'security_audit' => 'Security Audit',
];
