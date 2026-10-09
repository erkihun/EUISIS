<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PublicServiceStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service described on the public website.
 *
 * Not to be confused with ServiceType / PositionService, which model the tasks
 * employees perform for internal performance management.
 */
class PublicService extends Model
{
    use HasUuidPrimaryKey;
    use SoftDeletes;

    protected $fillable = [
        'code', 'slug', 'name_en', 'name_am', 'short_description_en', 'short_description_am',
        'full_description_en', 'full_description_am', 'eligibility_en', 'eligibility_am',
        'requirements_en', 'requirements_am', 'steps_en', 'steps_am', 'icon', 'action_route',
        'action_url', 'contact_info', 'status', 'is_featured', 'sort_order', 'published_at',
        'created_by', 'updated_by', 'published_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PublicServiceStatus::class,
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** The single definition of "publicly visible" for a service. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', PublicServiceStatus::Published->value);
    }
}
