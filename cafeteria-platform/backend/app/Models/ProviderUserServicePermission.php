<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderUserServicePermission extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'provider_user_id',
        'service_type_id',
        'permission_key',
        'is_allowed',
        'granted_by',
        'granted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_allowed' => 'boolean',
            'granted_at' => 'datetime',
        ];
    }

    public function providerUser(): BelongsTo
    {
        return $this->belongsTo(ProviderUser::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }
}
