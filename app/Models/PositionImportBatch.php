<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\Positions\PositionImportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single uploaded file of positions.
 *
 * @see PositionImportService
 */
class PositionImportBatch extends Model
{
    use HasUuidPrimaryKey;

    public const STATUS_PENDING = 'pending';

    /** Rows have been checked; nothing written to `positions` yet. */
    public const STATUS_VALIDATED = 'validated';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uploaded_by',
        'organization_id',
        'file_name',
        'total_rows',
        'valid_rows',
        'failed_rows',
        'status',
        'error_summary',
    ];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'failed_rows' => 'integer',
            'error_summary' => 'array',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PositionImportBatchRow::class, 'batch_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** A checked batch with at least one valid row may be committed. */
    public function isImportable(): bool
    {
        return $this->status === self::STATUS_VALIDATED
            && $this->valid_rows > 0;
    }

    /** Template rows no position was typed into. */
    public function unfilledRows(): int
    {
        return (int) ($this->error_summary['unfilled'] ?? 0);
    }

    /** Rows beyond the reader's cap, which were never checked. */
    public function overLimitRows(): int
    {
        return (int) ($this->error_summary['over_limit'] ?? 0);
    }

    /** Positions created so far. A large file is imported over several passes. */
    public function importedRows(): int
    {
        return (int) ($this->error_summary['imported'] ?? 0);
    }

    /** Checked rows still waiting to become positions. */
    public function remainingRows(): int
    {
        return $this->rows()->where('status', PositionImportBatchRow::STATUS_VALID)->count();
    }

    /** True once the import ran to its last row, whether or not every row went in. */
    public function isFinished(): bool
    {
        return $this->status !== self::STATUS_VALIDATED
            && array_key_exists('imported', $this->error_summary ?? []);
    }

    /** True when an import started and rows are still waiting: it was interrupted, or the next pass is due. */
    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_VALIDATED
            && array_key_exists('imported', $this->error_summary ?? []);
    }
}
