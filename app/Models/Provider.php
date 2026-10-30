<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Features;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    // cafeteriaProvider(), cafeterias() and cafeteriaNetworks() are attached by the Cafeteria module.
    // transportProfile() is attached by the Transport module (Modules/Transport/Providers).

    public function hasService(string $serviceCode): bool
    {
        // A service of a disabled module (config/features.php) is never offered.
        if (! Features::serviceAvailable($serviceCode)) {
            return false;
        }

        return $this->services()
            ->where('status', 'active')
            ->whereHas('serviceType', fn ($query) => $query->where('code', $serviceCode)->where('is_active', true))
            ->exists();
    }
}
