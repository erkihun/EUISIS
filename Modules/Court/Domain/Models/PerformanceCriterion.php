<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerformanceCriterion extends Model
{
    protected $table = 'court_performance_evaluation_criteria';

    protected $fillable = [
        'name', 'name_am', 'category', 'weight',
        'description', 'active', 'sort_order',
    ];

    protected $casts = [
        'active' => 'boolean',
        'weight' => 'integer',
        'sort_order' => 'integer',
    ];

    public function scores(): HasMany
    {
        return $this->hasMany(PerformanceEvaluationScore::class, 'criterion_id');
    }

    public function getLocalNameAttribute(): string
    {
        $locale = app()->getLocale();
        if ($locale === 'am' && $this->name_am) {
            return $this->name_am;
        }
        return $this->name;
    }

    public function scopeActive($query)
    {
        return $query->where('active', true)->orderBy('sort_order');
    }
}
