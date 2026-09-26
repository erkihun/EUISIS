# Provider portal accounts (/provider-users)

`/provider-users` manages the accounts that external providers (cafeteria,
transport, …) use to sign in at `/provider/portal/login`. Each account is a
`provider_users` row, authenticated by the `provider` guard.

## Accounts

- **Provider.** Every account belongs to one provider (`providers`). The
  provider is chosen at creation and never changes. A person moving to
  another company gets a new account there.
- **Sign-in.** The account signs in with its email address or its username,
  so at least one is required. Both are unique across all accounts, deleted
  ones included, so a restored account never collides. A username may not
  contain `@`, because anything that looks like an email is looked up as one.
- **Phone number.** Optional. It is used for password reset codes by SMS. A
  number shared by several accounts matches none of them.
- **Can sign in** when the account is `active`, `portal_enabled` is on, not
  deleted, and its provider is active and not deleted.

## Roles and permissions

| Role     | Portal access                                                   |
| -------- | --------------------------------------------------------------- |
| owner    | Everything the provider's active services offer                  |
| manager  | Same as owner                                                   |
| operator | Only the per-user keys granted in `provider_user_service_permissions` |

The keys an operator can be granted are listed in
`App\Support\ProviderPortal\ProviderUserPermissionCatalog`: currently the
transport keys (`provider.transport.*`). Only keys offered by the provider's
active services are accepted. Cafeteria pages follow the provider's cafeteria
service and need no per-user key. These are portal keys, not staff (Spatie)
permissions.

## Passwords

- **At creation**, the administrator either leaves the password blank, which
  generates a one-time password shown once after saving, or types one that
  must pass the central `PasswordPolicy`.
- **After creation**, an administrator reset generates a one-time password,
  or accepts a typed one checked against the policy and the password history.
  It goes through `PasswordLifecycle`: history, remember-token rotation,
  audit, and a notification to the holder.
- **Either way**, `must_change_password` is set. The portal lets the holder
  reach only the profile page, where the password is changed, until they
  choose their own.
- **Self-service:** the holder can also reset their own password from the
  login page, with a code sent by email or SMS.

## Lifecycle and audit

| Action   | Effect                                                                  | Audit event              |
| -------- | ----------------------------------------------------------------------- | ------------------------ |
| Create   | Account + operator permissions                                          | `provider_user.created`   |
| Edit     | Name, sign-in names, phone, role, portal access, permissions            | `provider_user.updated`   |
| Suspend  | Status `suspended` + reason; an open session ends at its next request    | `provider_user.suspended` |
| Activate | Status `active`                                                         | `provider_user.activated` |
| Delete   | Soft delete; listed under *Deleted accounts*                            | `provider_user.deleted`   |
| Restore  | Back with its previous status and permissions                           | `provider_user.restored`  |

Password resets are audited as `temporary_password_assigned` or
`admin_password_reset`. Audit values never contain a password or a hash.

Authorization uses the `cafeteria-provider-users.*` permissions (see
`ProviderUserPolicy`). They cover accounts of every provider type. The
Cafeteria Admin role holds all of them.

## Legacy accounts

The former `/provider-users` page wrote to `service_provider_users`, which no
sign-in uses. To copy those accounts to the portal:

```bash
php artisan provider-users:migrate-legacy          # report only
php artisan provider-users:migrate-legacy --apply  # create the portal accounts
```

The provider is found from the account's service provider
(`cafeteria_providers.service_provider_id`) or its active cafeteria
assignments.

- **Exactly one provider found:** a migrated account becomes an `operator`,
  keeps its password hash, must change its password, and records
  `metadata.legacy_service_provider_user_id`.
- **No provider, or several:** reported as `NEEDS_DECISION`. Create these
  accounts by hand on `/provider-users`.
- **Email or username already taken:** skipped.

The command is safe to run again.
