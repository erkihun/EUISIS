<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staged row. `errors` is a list of {field, message, suggestion};
 * `resolved` holds the canonical ids chosen for its reference columns;
 * `matched_*` names the existing record the row turned out to be (never
 * duplicated), `created_*` the record a commit made from it.
 */
class DesignImportRow extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_MAPPING_REQUIRED = 'mapping_required';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = ['batch_id', 'row_number', 'data', 'status', 'errors', 'resolved', 'legacy_code', 'matched_type', 'matched_id', 'match_source', 'created_type', 'created_id', 'updated_at'];

    protected function casts(): array
    {
        return ['row_number' => 'integer', 'data' => 'array', 'errors' => 'array', 'resolved' => 'array', 'updated_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DesignImportBatch::class, 'batch_id');
    }
}
