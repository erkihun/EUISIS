<?php

declare(strict_types=1);

namespace CafeteriaSystem\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A cafeteria operator account.
 *
 * This is the Cafeteria System's OWN auth model — it deliberately does not use
 * the EUISIS user table or guard. An EUISIS administrator has no account here
 * unless one is created explicitly, and a cafeteria operator has no route into
 * EUISIS admin.
 */
class CafeteriaUser extends Authenticatable
{
    use HasUuids;
    use Notifiable;

    /** Roles, widest first. */
    public const ROLE_PROVIDER_ADMIN = 'provider_admin';

    public const ROLE_CAFETERIA_MANAGER = 'cafeteria_manager';

    public const ROLE_SCANNER = 'scanner';

    public const ROLE_REPORT_VIEWER = 'report_viewer';

    public const ROLES = [
        self::ROLE_PROVIDER_ADMIN,
        self::ROLE_CAFETERIA_MANAGER,
        self::ROLE_SCANNER,
        self::ROLE_REPORT_VIEWER,
    ];

    protected $table = 'cafeteria_users';

    protected $fillable = [
        'provider_id',
        'cafeteria_id',
        'name',
        'email',
        'password',
        'role',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isProviderAdmin(): bool
    {
        return $this->role === self::ROLE_PROVIDER_ADMIN;
    }

    /**
     * May operate the scan terminal and record service.
     *
     * `operator` is the legacy role created before the four-role model existed;
     * it is treated as a scanner so pre-existing accounts keep working.
     */
    public function canServe(): bool
    {
        return in_array($this->role, [
            self::ROLE_PROVIDER_ADMIN,
            self::ROLE_CAFETERIA_MANAGER,
            self::ROLE_SCANNER,
            'operator',
        ], true);
    }

    /** May create providers' cafeterias, users and organization assignments. */
    public function canManage(): bool
    {
        return in_array($this->role, [
            self::ROLE_PROVIDER_ADMIN,
            self::ROLE_CAFETERIA_MANAGER,
        ], true);
    }

    /**
     * Cafeteria ids this user may act on.
     *
     * A provider_admin covers every cafeteria under their provider; everyone
     * else is confined to the single cafeteria they are bound to. Returning an
     * array (never null) means callers can always `whereIn` safely — an
     * unbound non-admin gets an empty set and therefore sees nothing.
     *
     * @return array<int, string>
     */
    public function accessibleCafeteriaIds(): array
    {
        if ($this->isProviderAdmin()) {
            return Cafeteria::query()
                ->where('provider_id', $this->provider_id)
                ->pluck('id')
                ->all();
        }

        return $this->cafeteria_id === null ? [] : [(string) $this->cafeteria_id];
    }

    public function canAccessCafeteria(?string $cafeteriaId): bool
    {
        return $cafeteriaId !== null && in_array($cafeteriaId, $this->accessibleCafeteriaIds(), true);
    }

    /** @return BelongsTo<CafeteriaProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'provider_id');
    }

    /** @return BelongsTo<Cafeteria, $this> */
    public function cafeteria(): BelongsTo
    {
        return $this->belongsTo(Cafeteria::class, 'cafeteria_id');
    }
}
