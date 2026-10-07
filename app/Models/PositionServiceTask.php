<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Main Task (ዋና ተግባር) of a sub-service. Master data: daily execution is
 * recorded on daily activity items, never here.
 */
class PositionServiceTask extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'sub_service_id',
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

    public function subService(): BelongsTo
    {
        return $this->belongsTo(PositionServiceSubService::class, 'sub_service_id');
    }

    public function positionService(): BelongsTo
    {
        return $this->belongsTo(PositionService::class);
    }

    public function standards(): HasMany
    {
        return $this->hasMany(PositionServiceTaskStandard::class, 'task_id');
    }
}
