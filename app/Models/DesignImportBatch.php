<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded file of design records in staging (ADR-LB-004). Nothing in it
 * is authoritative until a person commits it; the file stays on the private
 * disk so a corrected re-run reads the same bytes.
 */
class DesignImportBatch extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_PARSING = 'parsing';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_MAPPING_REQUIRED = 'mapping_required';

    public const STATUS_COMMITTING = 'committing';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_UPLOADED, self::STATUS_PARSING, self::STATUS_VALIDATED, self::STATUS_MAPPING_REQUIRED, self::STATUS_COMMITTING, self::STATUS_COMMITTED, self::STATUS_FAILED, self::STATUS_CANCELLED];

    protected $fillable = [
        'reference_number', 'organization_id', 'entity_type', 'baseline_id', 'file_name', 'file_disk', 'file_path', 'fingerprint', 'status',
        'total_rows', 'valid_rows', 'invalid_rows', 'mapping_required_rows', 'committed_rows', 'matched_rows', 'error_summary',
        'uploaded_by', 'committed_by', 'parsed_at', 'committed_at',
    ];

    protected $hidden = ['file_disk', 'file_path'];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer', 'valid_rows' => 'integer', 'invalid_rows' => 'integer', 'mapping_required_rows' => 'integer', 'committed_rows' => 'integer', 'matched_rows' => 'integer',
            'error_summary' => 'array', 'parsed_at' => 'datetime', 'committed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function baseline(): BelongsTo
    {
        return $this->belongsTo(LegacyBaseline::class, 'baseline_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(DesignImportRow::class, 'batch_id')->orderBy('row_number');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }
}
