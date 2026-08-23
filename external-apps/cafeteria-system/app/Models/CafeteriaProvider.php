<?php

declare(strict_types=1);

namespace CafeteriaSystem\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CafeteriaProvider extends Model
{
    use HasUuids;

    protected $table = 'cafeteria_providers';

    protected $fillable = [
        'code',
        'name',
        'branch_name',
        'status',
        'contact_person',
        'contact_phone',
        'settlement_account',
    ];

    /** @return HasMany<Cafeteria, $this> */
    public function cafeterias(): HasMany
    {
        return $this->hasMany(Cafeteria::class, 'provider_id');
    }

    /** @return HasMany<CafeteriaUser, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(CafeteriaUser::class, 'provider_id');
    }
}
