# Addis One — Sensitive Data Encryption at Rest

## Scope

The following PII columns are encrypted at the application layer using
Laravel's `encrypted` Eloquent cast (AES-256-CBC + HMAC, keyed by `APP_KEY`).

| Table | Column | Cast | Searchable hash column |
|---|---|---|---|
| `users` | `national_id` | encrypted | `national_id_hash` (SHA-256, indexed) |
| `users` | `phone_number` | encrypted | — |
| `users` | `two_factor_secret` | encrypted | — |
| `users` | `two_factor_recovery_codes` | encrypted | — |
| `employees` | `national_id` | encrypted | `national_id_hash` (SHA-256, indexed) |

`employees.phone` is **not** encrypted because `DetectDuplicateEmployeeAction`
performs direct equality lookups against it. Encrypting it would require an
additional hash column and is deferred until justified.

## Why the hash column?

Once a column is stored as encrypted ciphertext, each row's value is
non-deterministic (random IV) and direct WHERE/UNIQUE lookups stop working.
The accompanying `*_hash` column stores a deterministic SHA-256 of the
plaintext so:

- `Rule::unique('users', 'national_id_hash')` keeps protecting against
  duplicates without revealing the plaintext.
- Future code can run point lookups via `where('national_id_hash', hash('sha256', $value))`.

The hash is maintained automatically by the model `booted()` hooks on both
`User` and `Employee`: whenever `national_id` is dirty on save, the hash is
recomputed. Tests that bypass the model (raw `DB::table` inserts) must set
the hash manually.

## Validation rules

Form requests prepare the plaintext and compute the hash in
`prepareForValidation()`, then validate uniqueness against the hash:

```php
protected function prepareForValidation(): void
{
    $nid = trim((string) $this->input('national_id', '')) ?: null;
    $this->merge([
        'national_id'      => $nid,
        'national_id_hash' => $nid !== null ? hash('sha256', $nid) : null,
    ]);
}

public function rules(): array
{
    return [
        'national_id'      => ['nullable', 'string', 'max:100'],
        'national_id_hash' => ['nullable', 'string', 'size:64',
            Rule::unique('users', 'national_id_hash')->ignore($userId)],
    ];
}
```

Touched requests:

- `app/Http/Requests/UserStoreRequest.php`
- `app/Http/Requests/UserUpdateRequest.php`
- `app/Http/Requests/ProfileUpdateRequest.php`
- `app/Http/Requests/EmployeeStoreRequest.php`
- `app/Http/Requests/EmployeeUpdateRequest.php`

## Migration command — encrypting legacy data

Existing rows written before the cast was added still contain plaintext on
disk. The command `sensitive-data:encrypt-existing` reads each row through
the raw query builder, detects whether the value is already an
encrypted Laravel payload (cipher payload always starts with `eyJ`), and
re-saves the row through the model so the cast kicks in.

```bash
# Dry run — counts only, no DB writes
php artisan sensitive-data:encrypt-existing --dry-run

# Live run
php artisan sensitive-data:encrypt-existing
```

The command never logs the actual values — only counts and per-row error
flags. It uses `chunkById(200)` for memory safety on large tables.

## Rollback

To revert the encryption casts:

1. Run a one-time backfill that decrypts each row and writes plaintext back.
2. Remove the `'national_id' => 'encrypted'` cast entries from
   `app/Models/User.php` and `app/Models/Employee.php`.
3. Roll back the migration `2026_05_25_000001_add_hash_columns_to_users_and_employees`
   if you also want to drop the `*_hash` columns.

⚠️ Without `APP_KEY` you cannot decrypt existing values. Treat the production
key as you would a database master password — back it up to your secrets
manager and rotate via `php artisan key:generate --show` only when paired
with a re-encryption job.
