<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

class PublicPageMeta extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'public_page_meta';

    protected $fillable = [
        'page', 'meta_title_en', 'meta_title_am', 'meta_description_en', 'meta_description_am',
        'canonical_url', 'og_image_path', 'noindex', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['noindex' => 'boolean'];
    }
}
