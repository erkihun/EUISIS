<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiScope;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * An approved external system permitted to call the integration API.
 *
 * Tokens are issued through Sanctum against this model, so an application's
 * access can be revoked without touching any human user account.
 */
class ExternalApplication extends Model implements AuthenticatableContract
{
    use Authorizable;
    use HasApiTokens;
    use HasUuidPrimaryKey;
    use SoftDeletes;

    /*
     * Sanctum resolves the token's tokenable as the authenticated "user", so
     * this model must satisfy Authenticatable. It has no password and never
     * logs in through a session — only the bearer-token guard — so the
     * credential methods are intentionally inert.
     */

    public function getAuthIdentifierName(): string
    {
        return $this->getKeyName();
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
        // No session login, so there is nothing to remember.
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    protected $fillable = [
        'name',
        'code',
        'contact_person',
        'contact_email',
        'callback_url',
        'status',
        'allowed_scopes',
        'rate_limit_per_minute',
        'allowed_ips',
        'created_by',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'allowed_scopes' => 'array',
            'allowed_ips' => 'array',
            'rate_limit_per_minute' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Scopes this application may be granted, filtered to known values so a
     * stale row cannot widen access by naming an ability that no longer exists.
     *
     * @return array<int, string>
     */
    public function grantableScopes(): array
    {
        return array_values(array_intersect(
            $this->allowed_scopes ?? [],
            ApiScope::values(),
        ));
    }

    public function allowsIp(?string $ip): bool
    {
        $allowed = $this->allowed_ips ?? [];

        // An empty allowlist means the application is not IP-restricted.
        if ($allowed === [] || $ip === null) {
            return true;
        }

        return in_array($ip, $allowed, true);
    }

    /** @return MorphMany<PersonalAccessToken, $this> */
    public function tokens(): MorphMany
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable');
    }

    /** @return BelongsToMany<ApiEndpointDefinition, $this> */
    public function endpoints(): BelongsToMany
    {
        return $this->belongsToMany(
            ApiEndpointDefinition::class,
            'external_application_endpoints',
            'external_application_id',
            'api_endpoint_definition_id',
        )
            ->withPivot(['id', 'allowed_scope', 'is_enabled', 'created_by'])
            ->withTimestamps();
    }

    /**
     * Whether this application may call a specific endpoint.
     *
     * An application with NO assignments is treated as unrestricted, so
     * integrations registered before endpoint assignment existed keep working
     * until an administrator narrows them. Once any endpoint is assigned, the
     * assignment list becomes authoritative and everything else is denied.
     */
    public function allowsEndpoint(string $method, string $uri): bool
    {
        if (! $this->relationLoaded('endpoints')) {
            $this->load('endpoints');
        }

        if ($this->endpoints->isEmpty()) {
            return true;
        }

        return $this->endpoints->contains(
            fn (ApiEndpointDefinition $endpoint): bool => $endpoint->pivot->is_enabled
                && strcasecmp($endpoint->method, $method) === 0
                && $endpoint->uri === $uri
                && $endpoint->status === ApiEndpointDefinition::STATUS_ACTIVE,
        );
    }
}
