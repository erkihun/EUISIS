<?php

declare(strict_types=1);

namespace Modules\PublicSite\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * A service listed on the public website, for the public assistant's
 * answers. Writes are refused; Public Site Management edits services.
 */
final class PublicServiceRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    /** The published status value (PublicServiceStatus::Published inside the module). */
    public const PUBLISHED = 'published';

    protected $table = 'public_services';

    protected function casts(): array
    {
        return ['is_featured' => 'boolean', 'sort_order' => 'integer', 'published_at' => 'datetime'];
    }

    /** Published services only, as on the public site. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED);
    }

    /** The name stored in polymorphic columns, as for the Public Site module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\PublicService';
    }
}
