<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * "In the old system this record was called X." Kept per organization and
 * kind so an import can recognise the record on a second run, and so the
 * original reference is never lost.
 */
class LegacyCodeMapping extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['organization_id', 'entity_type', 'legacy_code', 'canonical_type', 'canonical_id', 'source', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function canonical(): MorphTo
    {
        return $this->morphTo('canonical', 'canonical_type', 'canonical_id');
    }
}
