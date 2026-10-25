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
 * data, admin also manages users); scope = where (an organization and everything
 * under it in the published hierarchy, or the whole city when empty).
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLES = ['core_viewer', 'core_officer', 'core_admin'];

    protected $fillable = ['name', 'email', 'password', 'role', 'scope_organization_id', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function canChangeMasterData(): bool
    {
        return in_array($this->role, ['core_officer', 'core_admin'], true);
    }

    public function isCityWide(): bool
    {
        return $this->scope_organization_id === null;
    }
}
