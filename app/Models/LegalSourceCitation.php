<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * "This design record rests on that legal source." Polymorphic, so a
 * mandate, a work standard, a hierarchy version or a legacy baseline all
 * cite sources the same way without a column each.
 */
class LegalSourceCitation extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['legal_source_id', 'subject_type', 'subject_id', 'note', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LegalSource::class, 'legal_source_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo('subject', 'subject_type', 'subject_id');
    }
}
