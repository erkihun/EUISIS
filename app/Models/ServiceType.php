<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceType extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name_en',
        'name_am',
        'description',
        'description_en',
        'description_am',
        'module_key',
        'route_prefix',
        'icon',
        'is_active',
        'sort_order',
        'deleted_by',
        'deletion_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'sort_order' => 'integer',
        ];
    }

    public function providers(): HasMany
    {
        return $this->hasMany(ServiceProvider::class);
    }

    public function providerServices(): HasMany
    {
        return $this->hasMany(ProviderService::class);
    }

    public function entitlementRules(): HasMany
    {
        return $this->hasMany(EntitlementRule::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }
}
