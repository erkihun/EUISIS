<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Configurable rating scale (result bands or competency levels). Never hard-coded policy.
 */
class PerformanceRatingScale extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_rating_scales';

    protected $fillable = [
        'code',
        'name_en',
        'name_am',
        'scale_type',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function bands(): HasMany
    {
        return $this->hasMany(PerformanceRatingBand::class, 'scale_id')->orderBy('sort_order');
    }
}
