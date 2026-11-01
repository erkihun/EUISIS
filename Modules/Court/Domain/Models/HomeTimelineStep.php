<?php

namespace Modules\Court\Domain\Models;

use Modules\Court\Domain\Models\Concerns\HasAmharicText;
use Illuminate\Database\Eloquent\Model;

class HomeTimelineStep extends Model
{
    protected $table = 'court_home_timeline_steps';

    use HasAmharicText;

    protected $fillable = [
        'sort_order', 'is_active', 'title', 'description',
        'meta', 'duration', 'color',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
