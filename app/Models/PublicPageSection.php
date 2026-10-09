<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Content of one fixed slot on a public page. The set of pages and section keys
 * is defined by PublicSiteSectionRegistry; rows are never created ad hoc.
 */
class PublicPageSection extends Model
{
    use HasUuidPrimaryKey;

    protected $fillable = [
        'page', 'section_key', 'is_visible', 'sort_order', 'title_en', 'title_am',
        'subtitle_en', 'subtitle_am', 'body_en', 'body_am', 'options', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_visible' => 'boolean', 'sort_order' => 'integer', 'options' => 'array'];
    }
}
