<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sub-Service (ንዑስ አገልግሎት) of a position service (ዋና አገልግሎት).
 */
class PositionServiceSubService extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'position_service_id',
        'organization_id',
        'code',
        'name_en',
        'name_am',
        'description',
        'is_active',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'sort_order' => 'integer',
        ];
    }

    public function positionService(): BelongsTo
    {
        return $this->belongsTo(PositionService::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(PositionServiceTask::class, 'sub_service_id');
    }
}
