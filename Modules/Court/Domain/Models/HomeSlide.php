<?php

namespace Modules\Court\Domain\Models;

use Modules\Court\Domain\Models\Concerns\HasAmharicText;
use Illuminate\Database\Eloquent\Model;

class HomeSlide extends Model
{
    protected $table = 'court_home_slides';

    use HasAmharicText;

    protected $fillable = [
        'sort_order', 'is_active', 'badge', 'title', 'description',
        'primary_label', 'primary_href', 'secondary_label', 'secondary_href', 'bg_style', 'bg_image',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
