<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A system that consumes the Core (Recruitment, EPMS, Cafeteria, …). Its
 * scopes say what it may read or do, its owner organization what part of the
 * city it may see (none = the whole city). Its secret is a Sanctum token,
 * hashed at rest and shown once; the UUID is its public reference.
 */
class ApiClient extends Model
{
    use HasApiTokens;

    /** Least privilege: each scope opens one kind of data or one command. */
    public const SCOPES = [
        'organizations:read',
        'positions:read',
        'employees:basic-read',
        'employees:read',
        'employee-status:read',
        'assignments:read',
        'cards:verify',
    ];

    protected $fillable = ['name', 'description', 'owner_organization_id', 'scopes', 'allowed_ips', 'rate_limit_per_minute', 'is_active', 'expires_at'];

    protected $hidden = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $client): void {
            $client->uuid ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'scopes' => 'array', 'allowed_ips' => 'array', 'is_active' => 'boolean', 'rate_limit_per_minute' => 'integer',
            'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime', 'secret_rotated_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /** Why the client may not call the Core now, or null. */
    public function denial(?string $ip): ?string
    {
        return match (true) {
            $this->revoked_at !== null => 'CLIENT_REVOKED',
            ! $this->is_active => 'CLIENT_INACTIVE',
            $this->expires_at !== null && ! $this->expires_at->isFuture() => 'CLIENT_EXPIRED',
            ! empty($this->allowed_ips) && ! IpUtils::checkIp((string) $ip, $this->allowed_ips) => 'IP_NOT_ALLOWED',
            default => null,
        };
    }
}
