<?php

// Password policy messages (docs/password-security-policy.md). Never name
// the matched value, the breach service or the hashing scheme.
return [
    'contains_name' => 'Choose a password that does not contain your name.',
    'contains_username' => 'Your password must not contain your username.',
    'contains_email' => 'Your password must not contain your email address.',
    'contains_employee_number' => 'Your password must not contain your employee number.',
    'contains_phone' => 'Your password must not contain your phone number.',
    'common' => 'This password is too common or predictable. Choose a longer, less predictable password or passphrase.',
    'compromised' => 'This password has been exposed in a known data breach. Choose another password.',
    'breach_check_unavailable' => 'We could not check this password right now. Please try again in a moment.',
    'reused' => 'You cannot reuse your current password or any of your last :count passwords.',
    'set_own_password_in_profile' => 'Change your own password from your profile, where your current password is confirmed.',
    'temporary_password_created' => 'A one-time password was generated. Give it to the account holder securely; it is shown only now and must be changed at first sign-in.',
    'reset_link_sent' => 'If an account exists for that email address, a password reset link has been sent.',
    'notification' => [
        'changed' => ['title' => 'Your password was changed'],
        'reset' => ['title' => 'Your password was reset'],
        'admin_reset' => ['title' => 'Your password was reset by an administrator'],
        'account' => 'Account: :account',
        'body' => 'This happened at :time.',
        'not_you' => 'If you did not do this, contact your system administrator immediately.',
    ],
];
