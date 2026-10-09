<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransportRoute extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'provider_id',
        'route_code',
        'name_en',
        'name_am',
        'origin_en',
        'origin_am',
        'destination_en',
        'destination_am',
        'distance_km',
        'estimated_duration_minutes',
        'assigned_organization_id',
        'assigned_scope_type',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'distance_km' => 'decimal:2',
            'estimated_duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function assignedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'assigned_organization_id');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(TransportVehicle::class, 'assigned_route_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(TransportTrip::class);
    }
}
