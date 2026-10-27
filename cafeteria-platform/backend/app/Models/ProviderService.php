<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderService extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'provider_id',
        'service_type_id',
        'status',
        'enabled_at',
        'disabled_at',
        'settings',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }
}
