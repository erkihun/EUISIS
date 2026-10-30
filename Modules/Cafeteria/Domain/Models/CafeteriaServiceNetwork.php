<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Cafeteria\Domain\Enums\CafeteriaLocationType;

/** One provider's group of cafeteria locations: a main cafeteria, branches and service points. */
class CafeteriaServiceNetwork extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'provider_id',
        'code',
        'name_en',
        'name_am',
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function cafeterias(): HasMany
    {
        return $this->hasMany(CafeteriaProvider::class, 'cafeteria_service_network_id');
    }

    public function mainCafeteria(): HasOne
    {
        return $this->hasOne(CafeteriaProvider::class, 'cafeteria_service_network_id')
            ->where('location_type', CafeteriaLocationType::Main->value);
    }

    public function organizationAccess(): HasMany
    {
        return $this->hasMany(OrganizationCafeteriaAccess::class, 'cafeteria_service_network_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->trashed();
    }
}
