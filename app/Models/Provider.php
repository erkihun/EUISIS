<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Provider extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'provider_code',
        'provider_type_id',
        'name_en',
        'name_am',
        'assigned_organization_id',
        'assigned_scope_type',
        'contact_person',
        'phone_number',
        'email',
        'address',
        'logo_path',
        'status',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'phone_number',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function providerType(): BelongsTo
    {
        return $this->belongsTo(ProviderType::class);
    }

    public function assignedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'assigned_organization_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(ProviderService::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(ProviderUser::class);
    }

    /**
     * The provider's default cafeteria location: its main cafeteria, else the
     * oldest one. A provider may now run several (see cafeterias()).
     */
    public function cafeteriaProvider(): HasOne
    {
        return $this->hasOne(CafeteriaProvider::class)
            ->orderByRaw("case when location_type = 'main' then 0 else 1 end")
            ->oldest();
    }

    /** Every cafeteria location this provider operates, in any network. */
    public function cafeterias(): HasMany
    {
        return $this->hasMany(CafeteriaProvider::class);
    }

    public function cafeteriaNetworks(): HasMany
    {
        return $this->hasMany(CafeteriaServiceNetwork::class);
    }

    public function transportProfile(): HasOne
    {
        return $this->hasOne(TransportProviderProfile::class);
    }

    public function transportRoutes(): HasMany
    {
        return $this->hasMany(TransportRoute::class);
    }

    public function transportVehicles(): HasMany
    {
        return $this->hasMany(TransportVehicle::class);
    }

    public function transportDrivers(): HasMany
    {
        return $this->hasMany(TransportDriver::class);
    }

    public function transportTrips(): HasMany
    {
        return $this->hasMany(TransportTrip::class);
    }

    public function transportTransactions(): HasMany
    {
        return $this->hasMany(TransportTransaction::class);
    }

    public function hasService(string $serviceCode): bool
    {
        return $this->services()
            ->where('status', 'active')
            ->whereHas('serviceType', fn ($query) => $query->where('code', $serviceCode)->where('is_active', true))
            ->exists();
    }
}
