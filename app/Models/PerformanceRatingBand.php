<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceRatingBand extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'performance_rating_bands';

    protected $fillable = [
        'scale_id',
        'min_score',
        'max_score',
        'level_value',
        'label_en',
        'label_am',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'min_score' => 'decimal:4',
            'max_score' => 'decimal:4',
        ];
    }

    public function scale(): BelongsTo
    {
        return $this->belongsTo(PerformanceRatingScale::class, 'scale_id');
    }
}
