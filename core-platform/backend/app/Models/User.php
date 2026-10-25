<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
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
