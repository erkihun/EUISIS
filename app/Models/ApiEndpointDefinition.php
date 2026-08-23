<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single integration API endpoint in the catalog.
 *
 * Discovered fields (method, uri, action, middleware, scope) are overwritten on
 * every sync — the route table is the source of truth for them. Curated fields
 * (description, is_public_documented) are preserved so an administrator's notes
 * survive a re-sync.
 */
class ApiEndpointDefinition extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEPRECATED = 'deprecated';

    public const STATUS_DISABLED = 'disabled';

    /** @var array<int, string> */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_DEPRECATED, self::STATUS_DISABLED];

    protected $fillable = [
        'method',
        'uri',
        'route_name',
        'controller_action',
        'required_scope',
        'middleware',
        'auth_required',
        'rate_limit',
        'description',
        'version',
        'status',
        'is_public_documented',
        'created_by',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'middleware' => 'array',
            'auth_required' => 'boolean',
            'is_public_documented' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Request logs recorded against this endpoint's URI.
     *
     * Matched on the stored `endpoint` string rather than a foreign key: logs
     * are written by middleware at request time, when no catalog row is
     * guaranteed to exist yet.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ApiRequestLog::class, 'endpoint', 'uri');
    }

    /** @return BelongsToMany<ExternalApplication, $this> */
    public function externalApplications(): BelongsToMany
    {
        return $this->belongsToMany(
            ExternalApplication::class,
            'external_application_endpoints',
            'api_endpoint_definition_id',
            'external_application_id',
        )
            ->withPivot(['id', 'allowed_scope', 'is_enabled', 'created_by'])
            ->withTimestamps();
    }

    /** Endpoints an administrator may assign to an application. */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)->where('is_public_documented', true);
    }

    public function scopeDocumented(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)->where('is_public_documented', true);
    }

    /**
     * Functional grouping for the documentation page, derived from the URI so
     * a newly synced endpoint lands in a sensible group without manual work.
     */
    public function documentationGroup(): string
    {
        return match (true) {
            str_contains($this->uri, 'id-cards') || str_contains($this->uri, 'cards/verify') => 'id_card_verification',
            str_contains($this->uri, 'service-eligibility') || str_contains($this->uri, 'authorize') => 'service_eligibility',
            str_contains($this->uri, 'transactions') || str_contains($this->uri, 'offline-sync') => 'service_transactions',
            str_contains($this->uri, 'employees') => 'employee_verification',
            str_contains($this->uri, 'settlements') || str_contains($this->uri, 'organizations') => 'reports',
            str_contains($this->uri, 'health') || str_contains($this->uri, 'status') => 'system_health',
            default => 'other',
        };
    }
}
