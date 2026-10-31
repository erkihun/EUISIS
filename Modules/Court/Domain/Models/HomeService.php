<?php

namespace Modules\Court\Domain\Models;

use Modules\Court\Domain\Models\Concerns\HasAmharicText;
use Illuminate\Database\Eloquent\Model;

class HomeService extends Model
{
    protected $table = 'court_home_services';

    use HasAmharicText;

    protected $fillable = [
        'sort_order', 'is_active', 'title', 'description',
        'meta', 'features', 'icon_type', 'accent', 'href',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'features'  => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
