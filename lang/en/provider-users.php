<?php

declare(strict_types=1);

return [
    'created' => 'Provider portal account created.',
    'updated' => 'Provider portal account updated.',
    'suspended' => 'Account suspended. The holder is signed out of the provider portal at their next request.',
    'activated' => 'Account activated.',
    'password_reset' => 'Password reset. The holder must choose a new one at their next sign-in.',
    'deleted' => 'Account deleted. It can be restored from the deleted accounts list.',
    'restored' => 'Account restored.',
    'email_or_username_required' => 'Enter an email address or a username. The account signs in with one of them.',
    'permission_not_offered' => 'One or more permissions are not offered by this provider\'s active services.',

    'attributes' => [
        'provider' => 'provider',
        'name' => 'full name',
        'email' => 'email address',
        'username' => 'username',
        'phone_number' => 'phone number',
        'role' => 'role',
        'permissions' => 'permissions',
        'password' => 'password',
    ],

    'legacy' => [
        'none' => 'No legacy provider user accounts (service_provider_users) to migrate.',
        'migrated' => 'Migrated: :email',
        'skipped_existing' => 'Skipped (already a portal account with this email or username): :email',
        'needs_decision' => 'NEEDS_DECISION: :email: :reason',
        'no_provider' => 'no provider could be matched to its service provider',
        'ambiguous_provider' => 'its service provider maps to several providers',
        'no_sign_in' => 'it has neither an email address nor a username',
        'summary' => ':migrated migrated, :skipped skipped, :decisions need a decision.',
        'dry_run' => 'Dry run: nothing was written. Run again with --apply to migrate.',
    ],
];
