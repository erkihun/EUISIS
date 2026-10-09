<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentResultBandPolicy extends Model
{
    use HasUuidPrimaryKey;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['range_min' => 'decimal:4', 'range_max' => 'decimal:4', 'requires_full_coverage' => 'bool',
            'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'activated_at' => 'datetime'];
    }

    public function bands(): HasMany
    {
        return $this->hasMany(AssessmentResultBand::class, 'policy_id')->orderBy('sort_order')->orderBy('min_score');
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }
}
