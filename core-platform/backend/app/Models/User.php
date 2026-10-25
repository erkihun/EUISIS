<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;

/**
 * A Core platform user. Role = what (viewer reads, officer also changes master
 * data, admin also manages users); scope = where, always explicit (ADR 0008):
 * the whole city (citywide), an organization and everything under it in the
 * hierarchy in force (subtree), or that organization alone (self). An account
 * without a scope reaches no organizational data.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLES = ['core_viewer', 'core_officer', 'core_admin'];

    public const SCOPE_CITYWIDE = 'citywide';

    public const SCOPE_SUBTREE = 'subtree';

    public const SCOPE_SELF = 'self';

    /** The scope kinds tied to one organization. */
    public const ORGANIZATION_SCOPES = [self::SCOPE_SUBTREE, self::SCOPE_SELF];

    protected $fillable = ['name', 'email', 'password', 'role', 'scope_organization_id', 'scope_type', 'is_active'];

    /** Credentials and second-factor secrets never leave the Core. */
    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_at' => 'datetime',
            'two_factor_enabled' => 'boolean',
        ];
    }

    /** Enrolment finished: a secret saved and confirmed with a first code. */
    public function hasMfaEnabled(): bool
    {
        return (bool) $this->two_factor_enabled && $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * A fresh set of one-time recovery codes, returned in clear to be shown
     * once; only their hashes are kept (and encrypted at rest).
     *
     * @return list<string>
     */
    public function regenerateMfaRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = bin2hex(random_bytes(5));
            $codes[] = strtoupper(substr($raw, 0, 5).'-'.substr($raw, 5, 5));
        }
        $this->two_factor_recovery_codes = (string) json_encode(array_map(fn (string $code): string => Hash::make($code), $codes));

        return $codes;
    }

    /** Accepts a recovery code once: it is removed as it is used. The caller holds the row lock. */
    public function consumeMfaRecoveryCode(string $code): bool
    {
        $codes = json_decode((string) $this->two_factor_recovery_codes, true) ?: [];
        foreach ($codes as $index => $hashed) {
            if (Hash::check(strtoupper(trim($code)), $hashed)) {
                unset($codes[$index]);
                $this->two_factor_recovery_codes = (string) json_encode(array_values($codes));
                $this->save();

                return true;
            }
        }

        return false;
    }

    public function canChangeMasterData(): bool
    {
        return in_array($this->role, ['core_officer', 'core_admin'], true);
    }

    /** Only an explicit city-wide grant reaches the whole city; an empty scope never does. */
    public function isCityWide(): bool
    {
        return $this->scope_type === self::SCOPE_CITYWIDE;
    }

    /** Whether the account reaches any organizational data at all. */
    public function hasOrganizationScope(): bool
    {
        return $this->isCityWide()
            || (in_array($this->scope_type, self::ORGANIZATION_SCOPES, true) && $this->scope_organization_id !== null);
    }
}
