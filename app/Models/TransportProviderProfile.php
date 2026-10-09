<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransportProviderProfile extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'provider_id',
        'license_number',
        'registration_number',
        'contact_person',
        'dispatch_phone',
        'service_area_description_en',
        'service_area_description_am',
        'operating_days',
        'operating_hours',
        'status',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'dispatch_phone',
    ];

    protected function casts(): array
    {
        return [
            'operating_days' => 'array',
            'operating_hours' => 'array',
            'metadata' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
