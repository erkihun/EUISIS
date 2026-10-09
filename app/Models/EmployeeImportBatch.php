<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\Employees\EmployeeCsvImportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single employee CSV upload.
 *
 * @see EmployeeCsvImportService
 */
class EmployeeImportBatch extends Model
{
    use HasUuidPrimaryKey;

    /** Awaiting validation. */
    public const STATUS_PENDING = 'pending';

    /** Rows have been checked; nothing written to `employees` yet. */
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
        return $this->hasMany(EmployeeImportBatchRow::class, 'batch_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Only a fully valid batch may be committed. */
    public function isImportable(): bool
    {
        return $this->status === self::STATUS_VALIDATED
            && $this->failed_rows === 0
            && $this->valid_rows > 0;
    }
}
