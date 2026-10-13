<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A service classification entry: the city's LOCAL scheme, or a CPC
 * reference. City-level reference data (brief §12–14): institutions cite
 * it, only classification stewards change it, and a new revision is a new
 * set of rows so historical links keep the revision they used.
 */
class ServiceClassification extends Model
{
    use HasUuidPrimaryKey;

    public const SCHEME_LOCAL = 'LOCAL';

    public const SCHEME_CPC = 'CPC';

    public const SCHEMES = [self::SCHEME_LOCAL, self::SCHEME_CPC];

    protected $fillable = ['scheme', 'revision', 'code', 'parent_id', 'level', 'name_en', 'name_am', 'description_en', 'description_am', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('code');
    }
}
