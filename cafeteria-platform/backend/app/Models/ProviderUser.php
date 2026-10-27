<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class ProviderUser extends Authenticatable
{
    use HasUuidPrimaryKey;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'provider_id',
        'name',
        'email',
        'username',
        'phone_number',
        'password',
        'provider_role',
        'status',
        'portal_enabled',
        'must_change_password',
        'last_login_at',
        'last_login_ip',
        'email_verified_at',
        'created_by',
        'updated_by',
        'suspended_by',
        'suspended_at',
        'suspension_reason',
        'metadata',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'phone_number',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'suspended_at' => 'datetime',
            'portal_enabled' => 'boolean',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
            'metadata' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function servicePermissions(): HasMany
    {
        return $this->hasMany(ProviderUserServicePermission::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPortalEnabled(): bool
    {
        return (bool) $this->portal_enabled;
    }

    public function canLogin(): bool
    {
        return $this->isActive()
            && $this->isPortalEnabled()
            && $this->provider !== null
            && $this->provider->status === 'active';
    }

    public function hasService(string $serviceCode): bool
    {
        return $this->provider?->hasService($serviceCode) ?? false;
    }

    public function canUseServicePermission(string $permissionKey): bool
    {
        if (in_array($this->provider_role, ['owner', 'manager'], true)) {
            return true;
        }

        return $this->servicePermissions()
            ->where('permission_key', $permissionKey)
            ->where('is_allowed', true)
            ->exists();
    }

    public function initials(): string
    {
        $words = array_filter(explode(' ', $this->name ?? ''));
        $letters = array_map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), $words);

        return implode('', array_slice($letters, 0, 2));
    }
}
