<?php

declare(strict_types=1);

namespace Modules\Court\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class PerformanceEvaluationCategory extends Model
{
    protected $table = 'court_performance_evaluation_categories';

    protected $fillable = [
        'slug',
        'name',
        'name_am',
        'active',
        'sort_order',
    ];

    protected $casts = [
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getLocalNameAttribute(): string
    {
        if (app()->getLocale() === 'am' && $this->name_am) {
            return $this->name_am;
        }

        return $this->name;
    }
}
