<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use App\Models\User as PlatformUser;
use App\Support\Auth\ActsThroughPlatformAccount;
use Database\Factories\Court\CourtStaffMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Modules\Court\Domain\Models\Concerns\AuditableContract as AuditableContract;
use Modules\Court\Domain\Models\Concerns\Auditable;

/**
 * A platform account that works in the court (ADR 0020): its court roles, teams,
 * signature and stamp. The row shares its id with `users.id`; the account signs
 * in, changes its password and confirms MFA in EUISIS, so none of that is here.
 * Court routes resolve it on the `court` guard (CourtServiceProvider).
 */
class CourtStaffMember extends Authenticatable implements AuditableContract, ActsThroughPlatformAccount
{
    // API tokens of the court's mobile API (court-ms issued them to staff too).
    use HasApiTokens, HasFactory, Notifiable, Auditable;

    public const string ACTIVE_ROLE_SESSION_KEY = 'active_admin_role_id';

    protected $table = 'court_staff';

    public $incrementing = false;

    private bool $activeRoleResolved = false;

    private ?int $resolvedActiveRoleId = null;

    private ?Role $resolvedActiveRole = null;

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'name',
        'email',
        'status',          // 'active' | 'inactive'
        'gender',
        'date_of_birth',
        'national_id',
        'position',
        'phone',
        'address',
        'avatar_path',
        'signature_path',
        'stamp_path',
    ];

    /**
     * Hidden attributes for arrays/JSON.
     */
    protected $hidden = [];

    /**
     * Attribute casting.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'must_change_password' => 'boolean',
            'date_of_birth'     => 'date:Y-m-d',
            'euisis_linked_at' => 'datetime',
            'euisis_suspended_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CourtStaffMemberFactory
    {
        return CourtStaffMemberFactory::new();
    }

    /** Court relations keep their `user_id` columns (pivots, notes, letters). */
    public function getForeignKey(): string
    {
        return 'user_id';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'id');
    }

    public function platformAccount(): PlatformUser
    {
        return $this->account()->firstOrFail();
    }

    /** A court role may ask for MFA; the platform account's own MFA policy decides (EUISIS). */
    public function requiresMfa(): bool
    {
        return false;
    }

    /** Second factors belong to the platform account and are confirmed in EUISIS. */
    public function hasConfirmedMfa(): bool
    {
        return false;
    }

    /**
     * Relationships
     */
    public function roles(): BelongsToMany
    {
        // pivot: role_user (role_id, user_id)
        return $this->belongsToMany(\Modules\Court\Domain\Models\Role::class, 'court_role_user')->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        // permissions via roles (pivot: permission_role)
        // keys: permission_role (permission_id, role_id)
        return $this->belongsToMany(
            \Modules\Court\Domain\Models\Permission::class,
            'court_permission_role',
            'role_id',
            'permission_id'
        )->withPivot('role_id');
    }

    /**
     * Permission / Role helpers
     */
    public function hasPermission(string $perm): bool
    {
        $activeRole = $this->activeRole();

        if ($activeRole instanceof Role) {
            if (mb_strtolower($activeRole->name) === 'admin') {
                return true;
            }

            $activeRole->loadMissing('permissions');

            return $activeRole->permissions->contains('name', $perm);
        }

        // Global bypass: Admin role has full permission access.
        if ($this->hasRole('admin')) {
            return true;
        }

        return $this->roles()
            ->whereHas('permissions', fn($q) => $q->where('name', $perm))
            ->exists();
    }

    public function hasRole(string $role): bool
    {
        $activeRole = $this->activeRole();

        if ($activeRole instanceof Role) {
            return mb_strtolower($activeRole->name) === mb_strtolower($role);
        }

        return $this->roles()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($role)])
            ->exists();
    }

    public function activeRole(): ?Role
    {
        $roleId = $this->activeRoleIdFromSession();

        if ($this->activeRoleResolved && $this->resolvedActiveRoleId === $roleId) {
            return $this->resolvedActiveRole;
        }

        $this->activeRoleResolved = true;
        $this->resolvedActiveRoleId = $roleId;
        $this->resolvedActiveRole = null;

        if ($roleId === null) {
            return null;
        }

        $roles = $this->relationLoaded('roles')
            ? $this->roles
            : $this->roles()->get();

        $role = $roles->firstWhere('id', $roleId);
        $this->resolvedActiveRole = $role instanceof Role ? $role : null;

        return $this->resolvedActiveRole;
    }

    private function activeRoleIdFromSession(): ?int
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        if (! $request->hasSession()) {
            return null;
        }

        $roleId = (int) $request->session()->get(self::ACTIVE_ROLE_SESSION_KEY, 0);

        return $roleId > 0 ? $roleId : null;
    }

    public function getIsAdminAttribute(): bool
    {
        return $this->hasRole('admin');
    }

    /** Convenience: allow admin to pass any perm */
    public function canDo(string $permission): bool
    {
        return $this->is_admin || $this->hasPermission($permission);
    }

    /**
     * Accessors for public URLs
     */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path && Storage::disk('court_public')->exists($this->avatar_path)
            ? Storage::disk('court_public')->url($this->avatar_path)
            : null;
    }

    public function getSignatureUrlAttribute(): ?string
    {
        return $this->signature_path && Storage::disk('court_public')->exists($this->signature_path)
            ? Storage::disk('court_public')->url($this->signature_path)
            : null;
    }

    public function getStampUrlAttribute(): ?string
    {
        return $this->stamp_path && Storage::disk('court_public')->exists($this->stamp_path)
            ? Storage::disk('court_public')->url($this->stamp_path)
            : null;
    }

    /**
     * Query scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'court_team_user')->withTimestamps();
    }
}
