<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A link on the public site. Its target is either an allow-listed public route
 * name or an https URL — enforced by PublicUrlPolicy on save.
 */
class PublicNavigationItem extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'public_navigation_items';

    protected $fillable = [
        'label_en', 'label_am', 'type', 'route_name', 'url', 'is_visible', 'is_system', 'sort_order', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_visible' => 'boolean', 'is_system' => 'boolean', 'sort_order' => 'integer'];
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }
}
