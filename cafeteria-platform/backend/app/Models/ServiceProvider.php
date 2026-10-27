<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceProvider extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'organization_id',
        'service_type_id',
        'name',
        'code',
        'status',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'is_demo' => 'bool',
        ];
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(ServiceTransaction::class);
    }

    public function cafeteriaDetails(): HasOne
    {
        return $this->hasOne(CafeteriaProvider::class);
    }
}
