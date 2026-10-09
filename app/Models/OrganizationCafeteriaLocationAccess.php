<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** A per-location exception to an organization's network access: one location allowed or excluded. */
class OrganizationCafeteriaLocationAccess extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'organization_cafeteria_location_access';

    protected $fillable = [
        'organization_cafeteria_access_id',
        'cafeteria_id',
        'is_allowed',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_allowed' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function access(): BelongsTo
    {
        return $this->belongsTo(OrganizationCafeteriaAccess::class, 'organization_cafeteria_access_id');
    }

    public function cafeteria(): BelongsTo
    {
        return $this->belongsTo(CafeteriaProvider::class, 'cafeteria_id');
    }

    public function coversDate(Carbon $date): bool
    {
        $day = $date->copy()->startOfDay();

        return $this->effective_from->lte($day) && ($this->effective_to === null || $this->effective_to->gte($day));
    }
}
