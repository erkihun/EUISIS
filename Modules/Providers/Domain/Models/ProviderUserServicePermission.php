<?php

declare(strict_types=1);

namespace Modules\Providers\Domain\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Entitlements\Contracts\ReadModels\ServiceTypeRecord;

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
        return $this->belongsTo(ServiceTypeRecord::class, 'service_type_id');
    }
}
