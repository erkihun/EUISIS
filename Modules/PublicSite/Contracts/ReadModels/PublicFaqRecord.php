<?php

declare(strict_types=1);

namespace Modules\PublicSite\Contracts\ReadModels;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Database\ReadOnlyModel;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * A public FAQ, for the public assistant's answers. Writes are refused;
 * Public Site Management edits FAQs.
 */
final class PublicFaqRecord extends ReadOnlyModel
{
    use HasUuidPrimaryKey;

    protected $table = 'public_faqs';

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'sort_order' => 'integer'];
    }

    /** Published FAQs only, as on the public site. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** The name stored in polymorphic columns, as for the Public Site module's model. */
    public function getMorphClass(): string
    {
        return 'App\Models\PublicFaq';
    }
}
